<?php
/** Step 5 smoke test — importers, certificate PDF, AI json-extract, mailer. */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require $root . '/includes/db.php';
$fails = [];
function check(string $l, bool $ok, string $d=''){ global $fails; echo ($ok?'  [OK] ':'  [FAIL] ').$l.($d?"   ($d)":'')."\n"; if(!$ok)$fails[]=$l; }

$dbfile = $root.'/data/_test_step5.sqlite'; @mkdir(dirname($dbfile),0775,true); @unlink($dbfile);
DB::boot(['db_driver'=>'sqlite','sqlite_path'=>$dbfile,'installed'=>true,'secret_key'=>'t']);
require $root.'/includes/schema.php'; create_schema();
require $root.'/includes/helpers.php'; require $root.'/includes/grading.php'; require $root.'/includes/quiz.php';
require $root.'/includes/importers.php'; require $root.'/includes/pdf.php'; require $root.'/includes/certificates.php';
require $root.'/includes/ai.php'; require $root.'/includes/mailer.php';

echo "Importers:\n";
$t = import_parse_text("Capital of France?\nA) Berlin\nB) Paris\nANSWER: B\n\nThe sky is blue.\nANSWER: True\n\nQ: 2+2?\nA: 4");
check('text: 3 parsed', count($t)===3, (string)count($t));
check('text: mcq correct index [1]', $t[0]['correct']===[1]);
check('text: true_false -> [0]', $t[1]['type']==='true_false' && $t[1]['correct']===[0]);
check('text: Q/A -> short_answer', $t[2]['type']==='short_answer' && $t[2]['correct']===['4']);
$c = import_parse_csv("type,text,o1,o2,o3,o4,correct,points\nmcq_single,X,A,B,C,D,C,2\nmcq_multi,Y,2,3,4,5,A|B|D,3");
check('csv: 2 parsed', count($c)===2);
check('csv: single C -> [2] pts 2', $c[0]['correct']===[2] && $c[0]['points']===2);
check('csv: multi A|B|D -> [0,1,3] pts 3', $c[1]['correct']===[0,1,3] && $c[1]['points']===3);
$j = import_parse_json('[{"type":"true_false","text":"T?","correct_answers":[1]}]');
check('json: parsed with correct_answers alias', count($j)===1 && $j[0]['correct']===[1]);

echo "\nImport into quiz:\n";
$uid = DB::insert("INSERT INTO users(email,password_hash,name,created_at) VALUES(?,?,?,?)",['t@t','x','T',now_ts()]);
$r = create_quiz($uid,'exam','Import Q'); $qid=(int)$r['id'];
$n = import_questions_into_quiz($qid, 'text', "Q1?\nA) a\nB) b\nANSWER: A");
check('import inserts + returns count', $n===1 && (int)DB::scalar("SELECT COUNT(*) FROM questions WHERE quiz_id=?",[$qid])===1);

echo "\nCertificate PDF:\n";
$cert = ['id'=>0,'serial'=>'QF-AAAA-BBBB','recipient_name'=>'Test User','percentage'=>91,'issued_at'=>now_ts(),'quiz_id'=>$qid];
$pdf = render_certificate_pdf($cert, 'Import Q', 'http://x/verify/QF-AAAA-BBBB');
check('PDF starts with %PDF', strncmp($pdf,'%PDF',4)===0);
check('PDF ends with %%EOF', substr(trim($pdf),-5)==='%%EOF');
check('PDF has xref + trailer', strpos($pdf,'xref')!==false && strpos($pdf,'trailer')!==false);
check('PDF non-trivial size', strlen($pdf) > 800, strlen($pdf).' bytes');
$serial = cert_make_serial();
check('serial format QF-XXXX-XXXX', (bool)preg_match('/^QF-[0-9A-F]{4}-[0-9A-F]{4}$/', $serial), $serial);

echo "\nissue_certificate_if_passed gating:\n";
// exam, passed
$q = ['id'=>$qid,'kind'=>'exam','pass_mark'=>50,'certificate_enabled'=>1];
$a = ['id'=>1,'percentage'=>80,'needs_grading'=>0,'student_name'=>'A','score'=>8,'max_score'=>10];
DB::run("INSERT INTO attempts(id,quiz_id,student_name,started_at,submitted_at,percentage,max_score,score,needs_grading) VALUES(1,?,?,?,?,?,?,?,0)",[$qid,'A',now_ts(),now_ts(),80,10,8]);
$s = issue_certificate_if_passed($q,$a);
check('passed exam issues cert', $s !== null);
check('re-issue returns same serial (idempotent)', issue_certificate_if_passed($q,$a) === $s);
$aFail = ['id'=>2,'percentage'=>30,'needs_grading'=>0,'student_name'=>'B','score'=>3,'max_score'=>10];
DB::run("INSERT INTO attempts(id,quiz_id,student_name,started_at,submitted_at,percentage,max_score,score,needs_grading) VALUES(2,?,?,?,?,?,?,?,0)",[$qid,'B',now_ts(),now_ts(),30,10,3]);
check('failed exam issues NO cert', issue_certificate_if_passed($q,$aFail) === null);
$qOff = ['id'=>$qid,'kind'=>'exam','pass_mark'=>50,'certificate_enabled'=>0];
$aOff = ['id'=>3,'percentage'=>90,'needs_grading'=>0,'student_name'=>'C','score'=>9,'max_score'=>10];
DB::run("INSERT INTO attempts(id,quiz_id,student_name,started_at,submitted_at,percentage,max_score,score,needs_grading) VALUES(3,?,?,?,?,?,?,?,0)",[$qid,'C',now_ts(),now_ts(),90,10,9]);
check('certificate_enabled=0 issues NO cert (even on pass)', issue_certificate_if_passed($qOff,$aOff) === null);

echo "\nAI json extraction + availability:\n";
check('extract from ```json fence', trim(_ai_extract_json("```json\n[{\"text\":\"x\"}]\n```")) === '[{"text":"x"}]');
check('extract bare array', _ai_extract_json('prefix [{"a":1}] suffix') === '[{"a":1}]');
check('ai_available false with no key', ai_available() === false);

echo "\nMailer:\n";
check('mail_configured false by default', mail_configured() === false);

@unlink($dbfile); @unlink($dbfile.'-wal'); @unlink($dbfile.'-shm');
echo "\n";
if ($fails) { echo 'FAILURES ('.count($fails)."):\n"; foreach($fails as $f) echo "  - $f\n"; exit(1); }
echo "All Step 5 checks passed.\n";
