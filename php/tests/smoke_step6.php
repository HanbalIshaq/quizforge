<?php
/** Step 6 (part 1) smoke test — ad slots + site stats. */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require $root . '/includes/db.php';
$fails = [];
function check(string $l, bool $ok, string $d=''){ global $fails; echo ($ok?'  [OK] ':'  [FAIL] ').$l.($d?"   ($d)":'')."\n"; if(!$ok)$fails[]=$l; }

$dbfile = $root.'/data/_test_step6.sqlite'; @mkdir(dirname($dbfile),0775,true); @unlink($dbfile);
DB::boot(['db_driver'=>'sqlite','sqlite_path'=>$dbfile,'installed'=>true,'secret_key'=>'t']);
require $root.'/includes/schema.php'; create_schema();
require $root.'/includes/helpers.php';

echo "Ad slots — show only when enabled AND code present:\n";
setting_set('ads_enabled','0');
setting_set('ad_code_header','<div>AD</div>');
check('disabled + code -> empty', ad_slot('header') === '');
setting_set('ads_enabled','1');
check('enabled + code -> renders code', strpos(ad_slot('header'), '<div>AD</div>') !== false);
check('enabled + code -> wrapped in qf-ad', strpos(ad_slot('header'), 'qf-ad') !== false);
setting_set('ad_code_footer','');
check('enabled + empty slot -> empty', ad_slot('footer') === '');
check('unknown slot -> empty', ad_slot('not_a_slot') === '');
setting_set('ads_enabled','0');
check('re-disabled -> empty again (code preserved)', ad_slot('header') === '');
check('code still stored after disable', setting_get('ad_code_header') === '<div>AD</div>');

echo "\nAd code is NOT escaped (trusted admin HTML):\n";
setting_set('ads_enabled','1');
setting_set('ad_code_header','<script>adsbygoogle</script>');
check('script tag passes through raw', strpos(ad_slot('header'), '<script>adsbygoogle</script>') !== false);
setting_set('ads_enabled','0');

echo "\nSite stats queries run:\n";
DB::insert("INSERT INTO users(email,password_hash,name,created_at,is_super_admin) VALUES(?,?,?,?,1)",['a@a','x','A',now_ts()]);
DB::insert("INSERT INTO users(email,password_hash,name,created_at) VALUES(?,?,?,?)",['b@b','x','B',now_ts()]);
$uid = 1;
DB::insert("INSERT INTO quizzes(user_id,title,share_code,kind,created_at,updated_at) VALUES(?,?,?,?,?,?)",[$uid,'Q','CODE1','exam',now_ts(),now_ts()]);
DB::insert("INSERT INTO attempts(quiz_id,student_name,started_at,submitted_at,percentage) VALUES(1,'S',?,?,80)",[now_ts(),now_ts()]);
DB::insert("INSERT INTO attempts(quiz_id,student_name,started_at) VALUES(1,'Live',?)",[now_ts()]); // in-progress
check('total users = 2', (int)DB::scalar("SELECT COUNT(*) FROM users") === 2);
check('submitted attempts = 1', (int)DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at IS NOT NULL") === 1);
$live = (int)DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at IS NULL AND started_at >= ?", [now_ts()-900]);
check('live-taking (in-progress <15min) = 1', $live === 1, (string)$live);

echo "\nAD_SLOTS catalogue:\n";
check('5 ad slots defined', count(AD_SLOTS) === 5);

@unlink($dbfile); @unlink($dbfile.'-wal'); @unlink($dbfile.'-shm');
echo "\n";
if ($fails) { echo 'FAILURES ('.count($fails)."):\n"; foreach($fails as $f) echo "  - $f\n"; exit(1); }
echo "All Step 6 (part 1) checks passed.\n";
