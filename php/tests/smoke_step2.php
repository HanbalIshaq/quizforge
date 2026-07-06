<?php
/**
 * Step 2 smoke test — quiz CRUD, question editor logic, grading engine.
 * Runs on SQLite (no MySQL needed). Run: php tests/smoke_step2.php
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

$dbfile = $root . '/data/_test_step2.sqlite';
@mkdir(dirname($dbfile), 0775, true); @unlink($dbfile);
$cfg = ['db_driver'=>'sqlite','sqlite_path'=>$dbfile,'installed'=>true,'secret_key'=>'t'];
DB::boot($cfg);
require $root . '/includes/schema.php'; create_schema();
require $root . '/includes/helpers.php';
require $root . '/includes/grading.php';
require $root . '/includes/quiz.php';

// Seed a user
$uid = DB::insert("INSERT INTO users(email,password_hash,name,created_at,is_super_admin,is_approved) VALUES(?,?,?,?,1,1)",
    ['t@t.local','x','T',now_ts()]);

echo "Quiz CRUD:\n";
$r = create_quiz($uid, 'exam', 'My Exam');
check('create_quiz returns id + share_code', !empty($r['id']) && !empty($r['share_code']));
$quiz = DB::one("SELECT * FROM quizzes WHERE id=?", [$r['id']]);
check('quiz saved with kind=exam', $quiz['kind']==='exam');
check('exam defaults show_correct_answers on', (int)$quiz['show_correct_answers']===1);
$r2 = create_quiz($uid, 'survey', 'My Survey');
$survey = DB::one("SELECT * FROM quizzes WHERE id=?", [$r2['id']]);
check('survey defaults require_name off', (int)$survey['require_name']===0);
$r3 = create_quiz($uid, 'form', 'My Form');
$form = DB::one("SELECT * FROM quizzes WHERE id=?", [$r3['id']]);
check('form defaults paginated on', (int)$form['paginated']===1);

echo "\nkind counts:\n";
$counts = quiz_kind_counts($uid);
check('counts all=3', $counts['all']===3, json_encode($counts));
check('counts exam=1, survey=1, form=1', $counts['exam']===1 && $counts['survey']===1 && $counts['form']===1);

echo "\nQuestion insert + JSON round-trip:\n";
$qid = DB::insert("INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position,explanation,time_limit_seconds,is_required) VALUES(?,?,?,?,?,?,?,?,?,?)",
    [$r['id'],'mcq_single','Capital of France?',json_encode(['Berlin','Madrid','Paris','Rome']),json_encode([2]),1,0,'',0,1]);
$qs = quiz_questions($r['id']);
check('quiz_questions decodes options to array', is_array($qs[0]['options']) && $qs[0]['options'][2]==='Paris');
check('quiz_questions decodes correct to array', $qs[0]['correct_answers']===[2]);

echo "\nGrading engine (all core types):\n";
$mcq = ['type'=>'mcq_single','options'=>['Berlin','Madrid','Paris','Rome'],'correct_answers'=>[2],'points'=>1];
check('mcq_single correct', grade_answer($mcq,2)[0]===true);
check('mcq_single wrong',   grade_answer($mcq,0)[0]===false);
check('mcq_single empty -> false+0', grade_answer($mcq,null)===[false,0.0,false]);

$tf = ['type'=>'true_false','options'=>['True','False'],'correct_answers'=>[0],'points'=>1];
check('true_false correct', grade_answer($tf,0)[0]===true);

$multi = ['type'=>'mcq_multi','options'=>['2','3','4','5'],'correct_answers'=>[0,1,3],'points'=>3];
check('mcq_multi exact match', grade_answer($multi,[0,1,3])===[true,3.0,false]);
check('mcq_multi order-independent', grade_answer($multi,[3,1,0])[0]===true);
check('mcq_multi missing one -> wrong', grade_answer($multi,[0,1])[0]===false);

$sa = ['type'=>'short_answer','correct_answers'=>['Hypertext Transfer Protocol'],'points'=>2];
check('short_answer case-insensitive', grade_answer($sa,'hypertext transfer protocol')===[true,2.0,false]);
check('short_answer wrong', grade_answer($sa,'nope')[0]===false);

$la = ['type'=>'long_answer','points'=>5];
check('long_answer needs manual', grade_answer($la,'essay text')===[null,0.0,true]);

$match = ['type'=>'matching','options'=>[['a'=>'FR','b'=>'Paris'],['a'=>'DE','b'=>'Berlin']],'correct_answers'=>[],'points'=>4];
check('matching all correct -> full', grade_answer($match,['Paris','Berlin'])===[true,4.0,false]);
check('matching half -> partial credit', grade_answer($match,['Paris','Madrid'])[1]===2.0);

$order = ['type'=>'ordering','options'=>['a','b','c'],'correct_answers'=>[0,1,2],'points'=>3];
check('ordering exact', grade_answer($order,[0,1,2])[0]===true);
check('ordering wrong', grade_answer($order,[0,2,1])[0]===false);

$hot = ['type'=>'hotspot','options'=>[['x'=>0.5,'y'=>0.5,'r'=>0.1]],'points'=>1];
check('hotspot inside radius', grade_answer($hot,['x'=>0.52,'y'=>0.5])[0]===true);
check('hotspot outside radius', grade_answer($hot,['x'=>0.9,'y'=>0.9])[0]===false);

$poll = ['type'=>'poll','options'=>['A','B'],'points'=>0];
check('poll ungraded (null)', grade_answer($poll,0)[0]===null);

echo "\nDelete quiz cascade:\n";
DB::run("INSERT INTO attempts(quiz_id,student_name,started_at) VALUES(?,?,?)", [$r['id'],'S',now_ts()]);
$aid = (int)DB::pdo()->lastInsertId();
DB::run("INSERT INTO answers(attempt_id,question_id,answer,points_earned,graded) VALUES(?,?,?,?,?)", [$aid,$qid,'2',1,1]);
DB::run("DELETE FROM answers WHERE attempt_id IN (SELECT id FROM attempts WHERE quiz_id=?)", [$r['id']]);
DB::run("DELETE FROM attempts WHERE quiz_id=?", [$r['id']]);
DB::run("DELETE FROM questions WHERE quiz_id=?", [$r['id']]);
DB::run("DELETE FROM quizzes WHERE id=?", [$r['id']]);
check('quiz gone after delete', !DB::scalar("SELECT 1 FROM quizzes WHERE id=?", [$r['id']]));
check('orphan questions gone', !DB::scalar("SELECT 1 FROM questions WHERE quiz_id=?", [$r['id']]));

echo "\nQuestion type catalogue:\n";
check('30 question types defined', count(question_types()) === 30, (string)count(question_types()));

@unlink($dbfile); @unlink($dbfile.'-wal'); @unlink($dbfile.'-shm');
echo "\n";
if ($fails) { echo 'FAILURES ('.count($fails)."):\n"; foreach ($fails as $f) echo "  - $f\n"; exit(1); }
echo "All Step 2 checks passed.\n";
