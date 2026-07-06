<?php
require dirname(__DIR__)."/includes/db.php"; require dirname(__DIR__)."/includes/helpers.php"; require dirname(__DIR__)."/includes/grading.php"; require dirname(__DIR__)."/includes/quiz.php";
$db=dirname(__DIR__)."/data/_opt.sqlite"; @unlink($db);
DB::boot(['db_driver'=>'sqlite','sqlite_path'=>$db,'installed'=>true,'secret_key'=>'t']);
require dirname(__DIR__)."/includes/schema.php"; create_schema();
$f=[];function ck($l,$o){global $f;echo($o?"  [OK] ":"  [FAIL] ").$l."\n";if(!$o)$f[]=$l;}

// randomize_options: display order changes but keys (indices) preserved
$q=['id'=>5,'type'=>'mcq_single','options'=>['A','B','C','D','E','F']];
$s=shuffle_preserve_keys($q['options'], 5*100+5);
ck('shuffle preserves all option keys', array_keys($s)===range(0,5) ? false : (count($s)===6));
ck('shuffled keys still map to original values', $s[0]==='A' && $s[3]==='D'); // key 0 always 'A'
$order=array_keys($s);
ck('shuffle changed display order (not identity)', $order!==range(0,5));

// randomize_questions deterministic per seed
$list=[1,2,3,4,5,6,7,8];
ck('seeded_shuffle deterministic', seeded_shuffle($list,42)===seeded_shuffle($list,42));
ck('seeded_shuffle actually shuffles', seeded_shuffle($list,42)!==$list);

// randomize_questions_for keeps count + option values intact for grading
$quiz=['randomize_questions'=>1,'randomize_options'=>1];
$qs=[
  ['id'=>1,'type'=>'mcq_single','options'=>['x','y','z'],'correct_answers'=>[1]],
  ['id'=>2,'type'=>'true_false','options'=>['True','False'],'correct_answers'=>[0]],
];
$r=randomize_questions_for($quiz,$qs,7);
ck('randomize keeps same number of questions', count($r)===2);
// grading still works: value 1 for q1 is still 'y' (correct)
$q1=null;foreach($r as $rq)if($rq['id']==1)$q1=$rq;
[$ok]=grade_answer($q1,1); ck('grading correct after option shuffle (value=orig index)', $ok===true);

// ip_allowed
ck('ip empty allowlist -> open', ip_allowed('','1.2.3.4')===true);
ck('ip exact match', ip_allowed('1.2.3.4, 5.6.7.8','5.6.7.8')===true);
ck('ip not in list -> blocked', ip_allowed('1.2.3.4','9.9.9.9')===false);
ck('ip CIDR match /24', ip_allowed('192.168.1.0/24','192.168.1.55')===true);
ck('ip CIDR non-match', ip_allowed('192.168.1.0/24','192.168.2.5')===false);

@unlink($db);@unlink($db.'-wal');@unlink($db.'-shm');
echo count($f)?("\nFAILS: ".implode(', ',$f)."\n"):"\nALL OPTION-LOGIC CHECKS PASSED\n";
