<?php
/**
 * Step 1 smoke test — foundation (DB, schema, auth). Runs on SQLite so it
 * needs no MySQL. Run:  php tests/smoke_step1.php
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__);
require $root . '/includes/db.php';

$fails = [];
function check(string $label, bool $ok, string $detail = '') {
    global $fails;
    echo ($ok ? '  [OK] ' : '  [FAIL] ') . $label . ($detail ? "   ($detail)" : '') . "\n";
    if (!$ok) $fails[] = $label;
}

// Fresh temp SQLite DB
$dbfile = $root . '/data/_test_step1.sqlite';
@mkdir(dirname($dbfile), 0775, true);
@unlink($dbfile);

$cfg = ['db_driver' => 'sqlite', 'sqlite_path' => $dbfile, 'installed' => true, 'secret_key' => 'test'];

echo "Boot + schema:\n";
DB::boot($cfg);
require $root . '/includes/schema.php';
create_schema();

$expectTables = ['users','quizzes','questions','attempts','answers','violations',
    'live_sessions','proctor_snapshots','question_bank','password_resets','site_settings',
    'certificates','ai_generations','organizations','org_members','org_invites'];
$rows = DB::all("SELECT name FROM sqlite_master WHERE type='table'");
$names = array_column($rows, 'name');
foreach ($expectTables as $t) {
    check("table '$t' exists", in_array($t, $names, true));
}

echo "\nAuth (register / login / lockout):\n";
$_SESSION = [];
require $root . '/includes/helpers.php';  // provides url(), settings, etc.
require $root . '/includes/auth.php';

// First user becomes super-admin
[$ok, $err, $uid] = register_user('admin@test.local', 'pass1234', 'Admin');
check('register first user succeeds', $ok, $err ?? '');
$u = DB::one("SELECT * FROM users WHERE id=?", [$uid]);
check('first user is super-admin', (int)$u['is_super_admin'] === 1);

// Second user is NOT super-admin
[$ok2] = register_user('user2@test.local', 'pass1234', 'User Two');
$u2 = DB::one("SELECT * FROM users WHERE email=?", ['user2@test.local']);
check('second user is NOT super-admin', (int)$u2['is_super_admin'] === 0);

// Duplicate email rejected
[$dupOk, $dupErr] = register_user('admin@test.local', 'pass1234', 'Dup');
check('duplicate email rejected', !$dupOk);

// Short password rejected
[$shortOk] = register_user('x@test.local', '123', 'Short');
check('short password rejected', !$shortOk);

// Wrong password fails login
$_SESSION = [];
[$lok, $lerr] = attempt_login('admin@test.local', 'WRONG');
check('wrong password fails', !$lok);

// Correct password logs in
$_SESSION = [];
[$lok2] = attempt_login('admin@test.local', 'pass1234');
check('correct password logs in', $lok2 && !empty($_SESSION['uid']));
check('session uid matches user', ($_SESSION['uid'] ?? null) == $uid);

// current_user returns the row
$cu = current_user();
check('current_user() returns the logged-in user', $cu && $cu['email'] === 'admin@test.local');

// Password is bcrypt-hashed (not plaintext)
check('password stored hashed', $u['password_hash'] !== 'pass1234' && strlen($u['password_hash']) > 40);

// Account lockout after threshold
$_SESSION = [];
for ($i = 0; $i < ACCOUNT_LOCKOUT_THRESHOLD; $i++) {
    attempt_login('user2@test.local', 'nope');
}
[$lockOk, $lockErr] = attempt_login('user2@test.local', 'pass1234');
check('account locks after too many fails', !$lockOk, $lockErr ?? '');

echo "\nFeature flags + settings:\n";
check('default feature_registration is on', feature_enabled('feature_registration'));
setting_set('feature_billing', '1');
check('setting_set persists', feature_enabled('feature_billing'));
setting_set('feature_billing', '0');
check('setting_set updates existing', !feature_enabled('feature_billing'));

// Cleanup
@unlink($dbfile);
@unlink($dbfile . '-wal');
@unlink($dbfile . '-shm');

echo "\n";
if ($fails) {
    echo 'FAILURES (' . count($fails) . "):\n";
    foreach ($fails as $f) echo "  - $f\n";
    exit(1);
}
echo "All Step 1 checks passed.\n";
