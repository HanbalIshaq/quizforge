<?php
/**
 * CLI demo-data seeder.  Usage (from the app folder):
 *     php tools/seed_demo.php [user_id]
 *
 * Seeds demo quizzes + sample responses for the given user (default: the
 * first super-admin). Safe to run multiple times — it adds a fresh set each
 * time (delete the demo quizzes from the dashboard if you want to reset).
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Run from the command line.\n"); exit(1); }

$root = dirname(__DIR__);
require $root . '/includes/db.php';
require $root . '/includes/helpers.php';

if (!is_installed()) { fwrite(STDERR, "Not installed — run install.php first.\n"); exit(1); }

DB::boot(config());
require $root . '/includes/schema.php';
require $root . '/includes/grading.php';
require $root . '/includes/quiz.php';
require $root . '/includes/seed.php';

$uid = isset($argv[1]) ? (int)$argv[1] : 0;
if (!$uid) {
    $row = DB::one("SELECT id FROM users WHERE is_super_admin=1 ORDER BY id LIMIT 1")
        ?: DB::one("SELECT id FROM users ORDER BY id LIMIT 1");
    if (!$row) { fwrite(STDERR, "No users found — create an admin account first.\n"); exit(1); }
    $uid = (int)$row['id'];
}

echo "Seeding demo data for user #$uid …\n";
$summary = seed_demo_data($uid);
echo "Done.\n";
echo "  Quizzes created: {$summary['quizzes']}\n";
echo "  Sample responses: {$summary['submissions']}\n";
echo "  Exam #{$summary['exam_id']}, Poll #{$summary['poll_id']}, Survey #{$summary['survey_id']}, Form #{$summary['form_id']}\n";
echo "Open the dashboard to explore.\n";
