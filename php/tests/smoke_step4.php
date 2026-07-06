<?php
/** Step 4 smoke test — poll/survey aggregate + secure file upload. */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require $root . '/includes/db.php';
$fails = [];
function check(string $l, bool $ok, string $d=''){ global $fails; echo ($ok?'  [OK] ':'  [FAIL] ').$l.($d?"   ($d)":'')."\n"; if(!$ok)$fails[]=$l; }

$dbfile = $root.'/data/_test_step4.sqlite'; @mkdir(dirname($dbfile),0775,true); @unlink($dbfile);
DB::boot(['db_driver'=>'sqlite','sqlite_path'=>$dbfile,'installed'=>true,'secret_key'=>'t']);
require $root.'/includes/schema.php'; create_schema();
require $root.'/includes/helpers.php'; require $root.'/includes/grading.php'; require $root.'/includes/quiz.php';

$uid = DB::insert("INSERT INTO users(email,password_hash,name,created_at) VALUES(?,?,?,?)",['t@t','x','T',now_ts()]);
$r = create_quiz($uid,'poll','P'); $qid=(int)$r['id'];
$qp = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'poll','Fav?',json_encode(['PHP','Python','JS']),json_encode([]),0,0]);
$qn = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'nps','Rec?',json_encode([]),json_encode([]),0,1]);
$qr = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'rating','Rate?',json_encode([]),json_encode([]),0,2]);
$qt = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'open_ended','Cmt?',json_encode([]),json_encode([]),0,3]);

// Simulate 4 submissions
function submit(int $qid, array $ans){
    $aid = DB::insert("INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at) VALUES(?,?,?,?)",[$qid,'V',now_ts(),now_ts()]);
    foreach ($ans as $q=>$v) DB::run("INSERT INTO answers(attempt_id,question_id,answer) VALUES(?,?,?)",[$aid,$q,json_encode($v)]);
}
submit($qid,[$qp=>0,$qn=>10,$qr=>5,$qt=>'great tool great']);   // PHP, promoter, 5
submit($qid,[$qp=>0,$qn=>9, $qr=>4,$qt=>'good good stuff']);     // PHP, promoter, 4
submit($qid,[$qp=>1,$qn=>3, $qr=>2,$qt=>'needs work']);          // Python, detractor, 2
submit($qid,[$qp=>2,$qn=>7, $qr=>4,$qt=>'nice']);                // JS, passive, 4

$agg = quiz_aggregate($qid);
$byId = []; foreach ($agg as $s) $byId[(int)$s['q']['id']] = $s;

echo "Choice aggregation:\n";
$c = $byId[$qp];
check('choice kind', $c['kind']==='choice');
check('PHP counted 2', ($c['counts'][0]??0)===2, json_encode($c['counts']));
check('Python counted 1', ($c['counts'][1]??0)===1);
check('JS counted 1', ($c['counts'][2]??0)===1);
check('total 4', $c['total']===4);

echo "\nNPS:\n";
$n = $byId[$qn];
check('nps kind', $n['kind']==='nps');
check('promoters=2 (9,10)', $n['promoters']===2, "got {$n['promoters']}");
check('passives=1 (7)', $n['passives']===1);
check('detractors=1 (3)', $n['detractors']===1);
check('NPS = (2-1)/4*100 = 25', (int)$n['nps']===25, "got {$n['nps']}");

echo "\nRating:\n";
$rt = $byId[$qr];
check('rating kind', $rt['kind']==='rating');
check('avg (5+4+2+4)/4 = 3.75', $rt['avg']==3.75, "got {$rt['avg']}");

echo "\nText + word cloud:\n";
$t = $byId[$qt];
check('text kind', $t['kind']==='text');
check('4 texts collected', count($t['texts'])===4);
check('"great" is top word (freq 2)', ($t['words']['great']??0)===2, json_encode(array_slice($t['words'],0,3,true)));

echo "\nSecure file upload validation (extension allowlist):\n";
// Build a fake $_FILES entry helper by writing temp files
function fakeUpload(string $name, string $content): array {
    $tmp = tempnam(sys_get_temp_dir(),'up'); file_put_contents($tmp,$content);
    return ['name'=>$name,'tmp_name'=>$tmp,'size'=>strlen($content),'error'=>UPLOAD_ERR_OK];
}
$png = "\x89PNG\r\n\x1a\n".str_repeat('x',50);
$_FILES['q_1'] = fakeUpload('photo.png',$png);
check('valid PNG accepted', handle_file_upload('q_1',$qid) !== null);
$_FILES['q_2'] = fakeUpload('evil.html','<script>alert(1)</script>');
check('.html rejected', handle_file_upload('q_2',$qid) === null);
$_FILES['q_3'] = fakeUpload('evil.svg','<svg onload=alert(1)>');
check('.svg rejected', handle_file_upload('q_3',$qid) === null);
$_FILES['q_4'] = fakeUpload('fake.png','<html>not a png</html>');
check('HTML disguised as .png rejected (magic byte)', handle_file_upload('q_4',$qid) === null);
$_FILES['q_5'] = fakeUpload('mal.exe','MZ'.str_repeat('x',50));
check('.exe rejected', handle_file_upload('q_5',$qid) === null);

// cleanup uploaded test files
@array_map('unlink', glob($root.'/uploads/quiz_'.$qid.'/*') ?: []);
@rmdir($root.'/uploads/quiz_'.$qid);
@unlink($dbfile); @unlink($dbfile.'-wal'); @unlink($dbfile.'-shm');
echo "\n";
if ($fails) { echo 'FAILURES ('.count($fails)."):\n"; foreach($fails as $f) echo "  - $f\n"; exit(1); }
echo "All Step 4 checks passed.\n";
