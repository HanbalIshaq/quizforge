<?php
/**
 * Step 3 smoke test — answer parsing + full attempt scoring loop.
 * Run: php tests/smoke_step3.php
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require $root . '/includes/db.php';

$fails = [];
function check(string $l, bool $ok, string $d = '') {
    global $fails; echo ($ok ? '  [OK] ' : '  [FAIL] ') . $l . ($d ? "   ($d)" : '') . "\n";
    if (!$ok) $fails[] = $l;
}

$dbfile = $root . '/data/_test_step3.sqlite';
@mkdir(dirname($dbfile), 0775, true); @unlink($dbfile);
DB::boot(['db_driver'=>'sqlite','sqlite_path'=>$dbfile,'installed'=>true,'secret_key'=>'t']);
require $root . '/includes/schema.php'; create_schema();
require $root . '/includes/helpers.php';
require $root . '/includes/grading.php';
require $root . '/includes/quiz.php';

echo "parse_submitted_answer:\n";
check('mcq_single "2" -> int 2', parse_submitted_answer('mcq_single','2') === 2);
check('mcq_single "" -> null', parse_submitted_answer('mcq_single','') === null);
check('mcq_multi [0,2] -> [0,2]', parse_submitted_answer('mcq_multi',['0','2']) === [0,2]);
check('short_answer trims', parse_submitted_answer('short_answer','  Paris ') === 'Paris');
check('number "42" -> 42', parse_submitted_answer('number','42') === 42);
check('rating "5" -> 5', parse_submitted_answer('rating','5') === 5);
check('empty text -> null', parse_submitted_answer('short_answer','') === null);

echo "\nrender_take_question emits inputs:\n";
$mcq = ['id'=>1,'type'=>'mcq_single','options'=>['A','B','C'],'is_required'=>1,'correct_answers'=>[0]];
$html = render_take_question($mcq);
check('mcq renders radio inputs', substr_count($html,'type="radio"')===3);
check('mcq marks required', strpos($html,'data-required="1"')!==false);
$sa = render_take_question(['id'=>2,'type'=>'short_answer','options'=>[],'is_required'=>0]);
check('short_answer renders text input', strpos($sa,'type="text"')!==false || strpos($sa,'name="q_2"')!==false);
$rating = render_take_question(['id'=>3,'type'=>'rating','options'=>[],'is_required'=>0]);
check('rating renders 5 stars', substr_count($rating,'qf-star')===5);

echo "\nFull attempt scoring loop (simulated submit):\n";
// Seed a scored quiz with 3 questions
$uid = DB::insert("INSERT INTO users(email,password_hash,name,created_at) VALUES(?,?,?,?)",['t@t','x','T',now_ts()]);
$r = create_quiz($uid,'exam','Scoring Test');
$qid = (int)$r['id'];
$q1 = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'mcq_single','Q1',json_encode(['A','B']),json_encode([1]),1,0]);
$q2 = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'mcq_multi','Q2',json_encode(['2','3','4']),json_encode([0,1]),2,1]);
$q3 = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position) VALUES(?,?,?,?,?,?,?)",
    [$qid,'short_answer','Q3',json_encode([]),json_encode(['paris']),1,2]);

function score_submission(int $qid, array $post): array {
    $questions = quiz_questions($qid);
    usort($questions, fn($a,$b)=>$a['id']<=>$b['id']);
    $total=0.0; $max=0.0;
    foreach ($questions as $q) {
        $max += (float)$q['points'];
        $val = parse_submitted_answer($q['type'], $post['q_'.$q['id']] ?? null);
        [$ok,$pts] = grade_answer($q, $val);
        if ($ok) $total += $pts;
    }
    return [$total, $max, $max>0?$total/$max*100:0];
}

// All correct
[$t,$m,$p] = score_submission($qid, ["q_$q1"=>'1', "q_$q2"=>['0','1'], "q_$q3"=>'Paris']);
check('all correct -> full score', $t===4.0 && $m===4.0 && (int)$p===100, "t=$t m=$m p=$p");
// Partial
[$t,$m,$p] = score_submission($qid, ["q_$q1"=>'1', "q_$q2"=>['0'], "q_$q3"=>'wrong']);
check('partial -> only q1 correct (1/4=25%)', $t===1.0 && (int)$p===25, "t=$t p=$p");
// All wrong / empty
[$t,$m,$p] = score_submission($qid, []);
check('empty submission -> 0', $t===0.0 && (int)$p===0);

echo "\nAttempt persisted + retrievable:\n";
$aid = DB::insert("INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,score,max_score,percentage) VALUES(?,?,?,?,?,?,?)",
    [$qid,'Alice',now_ts(),now_ts(),4,4,100]);
$got = DB::one("SELECT * FROM attempts WHERE id=?", [$aid]);
check('attempt row saved', $got && $got['student_name']==='Alice' && (int)$got['percentage']===100);

@unlink($dbfile); @unlink($dbfile.'-wal'); @unlink($dbfile.'-shm');
echo "\n";
if ($fails) { echo 'FAILURES ('.count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; exit(1); }
echo "All Step 3 checks passed.\n";
