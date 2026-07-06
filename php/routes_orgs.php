<?php
/**
 * Step 6 routes — Site-admin panel (super-admin only): live status, users,
 * tests overview, and ad management. (Orgs + live polling added next.)
 *
 * Distinct from the USER panel (/admin): that's each user's own workspace;
 * this (/admin/site) is the whole-platform control room for super-admins.
 */

declare(strict_types=1);

// ── Site-admin dashboard ──────────────────────────────────────────────────
route('GET', '/admin/site', function () {
    require_super_admin();
    $now = now_ts();
    $stats = [
        'users'        => (int) DB::scalar("SELECT COUNT(*) FROM users"),
        'users_24h'    => (int) DB::scalar("SELECT COUNT(*) FROM users WHERE created_at >= ?", [$now - 86400]),
        'quizzes'      => (int) DB::scalar("SELECT COUNT(*) FROM quizzes"),
        'questions'    => (int) DB::scalar("SELECT COUNT(*) FROM questions"),
        'attempts'     => (int) DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at IS NOT NULL"),
        'attempts_24h' => (int) DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at >= ?", [$now - 86400]),
        // "Live now" = attempts started but not submitted in the last 15 min
        'live_taking'  => (int) DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at IS NULL AND started_at >= ?", [$now - 900]),
        'certs'        => (int) DB::scalar("SELECT COUNT(*) FROM certificates"),
    ];
    // Recent activity feeds
    $recentUsers = DB::all("SELECT id,email,name,created_at,last_login_at,is_super_admin,is_suspended FROM users ORDER BY created_at DESC LIMIT 8");
    $recentQuizzes = DB::all(
        "SELECT q.id,q.title,q.kind,q.share_code,q.created_at,u.email AS owner
         FROM quizzes q JOIN users u ON u.id=q.user_id ORDER BY q.created_at DESC LIMIT 8"
    );
    $recentAttempts = DB::all(
        "SELECT a.id,a.student_name,a.percentage,a.submitted_at,q.title AS quiz_title,q.kind,u.email AS owner
         FROM attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN users u ON u.id=q.user_id
         WHERE a.submitted_at IS NOT NULL ORDER BY a.submitted_at DESC LIMIT 12"
    );
    page('site_dashboard', [
        'title' => 'Site admin · ' . app_name(),
        'stats' => $stats, 'recentUsers' => $recentUsers,
        'recentQuizzes' => $recentQuizzes, 'recentAttempts' => $recentAttempts,
    ]);
});

// ── Live status JSON (polled by the dashboard for a live counter) ─────────
route('GET', '/admin/site/live.json', function () {
    require_super_admin();
    $now = now_ts();
    header('Content-Type: application/json');
    echo json_encode([
        'live_taking'  => (int) DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at IS NULL AND started_at >= ?", [$now - 900]),
        'attempts_24h' => (int) DB::scalar("SELECT COUNT(*) FROM attempts WHERE submitted_at >= ?", [$now - 86400]),
        'users'        => (int) DB::scalar("SELECT COUNT(*) FROM users"),
        'ts' => $now,
    ]);
    exit;
});

// ── User management ───────────────────────────────────────────────────────
route('GET', '/admin/site/users', function () {
    require_super_admin();
    $users = DB::all(
        "SELECT u.*,
                (SELECT COUNT(*) FROM quizzes WHERE user_id=u.id) AS n_quizzes
         FROM users u ORDER BY u.created_at DESC"
    );
    page('site_users', ['title' => 'Users · ' . app_name(), 'users' => $users]);
});

route('POST', '/admin/site/users/{uid}/{action}', function ($p) {
    require_super_admin();
    $target = DB::one("SELECT * FROM users WHERE id=?", [(int)$p['uid']]);
    if (!$target) redirect('/admin/site/users');
    $me = (int)$_SESSION['uid'];
    // Guard: can't suspend/demote yourself
    if ((int)$target['id'] === $me && in_array($p['action'], ['suspend','demote'], true)) {
        flash('You cannot do that to your own account.', 'error');
        redirect('/admin/site/users');
    }
    switch ($p['action']) {
        case 'suspend':   DB::run("UPDATE users SET is_suspended=1 WHERE id=?", [$target['id']]); break;
        case 'unsuspend': DB::run("UPDATE users SET is_suspended=0 WHERE id=?", [$target['id']]); break;
        case 'promote':   DB::run("UPDATE users SET is_super_admin=1 WHERE id=?", [$target['id']]); break;
        case 'demote':    DB::run("UPDATE users SET is_super_admin=0 WHERE id=?", [$target['id']]); break;
        default: redirect('/admin/site/users');
    }
    flash("Updated {$target['email']}.", 'success');
    redirect('/admin/site/users');
});

// ── Site settings: features + ads ─────────────────────────────────────────
route('GET', '/admin/site/settings', function () {
    require_super_admin();
    $ads = [];
    foreach (AD_SLOTS as $s) $ads[$s] = setting_get('ad_code_' . $s, '');
    page('site_settings', [
        'title' => 'Site settings · ' . app_name(),
        'features' => features_all(),
        'ads_enabled' => ads_enabled(),
        'ads' => $ads,
    ]);
});

route('POST', '/admin/site/settings', function () {
    require_super_admin();
    // Feature flags (checkboxes: present = on)
    foreach (array_keys(FEATURE_DEFAULTS) as $flag) {
        setting_set($flag, isset($_POST[$flag]) ? '1' : '0');
    }
    // Ads
    setting_set('ads_enabled', isset($_POST['ads_enabled']) ? '1' : '0');
    foreach (AD_SLOTS as $s) {
        if (array_key_exists('ad_code_' . $s, $_POST)) {
            setting_set('ad_code_' . $s, (string)$_POST['ad_code_' . $s]);
        }
    }
    flash('Site settings saved.', 'success');
    redirect('/admin/site/settings');
});

// Later in Step 6: organizations + live polling.
