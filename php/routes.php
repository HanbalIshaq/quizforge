<?php
/**
 * Route definitions. Grows with each build step. Handlers are closures that
 * render pages or perform actions then redirect.
 *
 * $CSRF_EXEMPT / $CSRF_EXEMPT_PREFIXES list public POST endpoints that skip
 * CSRF (student quiz submit etc. — added in later steps).
 */

declare(strict_types=1);

$CSRF_EXEMPT = [];
$CSRF_EXEMPT_PREFIXES = ['/q/', '/live/']; // public take-quiz + live-play endpoints

// ── Home ────────────────────────────────────────────────────────────────
// Accessible to everyone (logged in or not) so users can always reach the
// landing page. The header + hero adapt to the auth state.
route('GET', '/', function () {
    page('home', ['title' => app_name() . ' — ' . app_tagline()]);
});

// ── Register ──────────────────────────────────────────────────────────────
route('GET', '/register', function () {
    if (!feature_enabled('feature_registration')) {
        flash('Public sign-up is disabled. Ask an admin to invite you.', 'error');
        redirect('/login');
    }
    page('register', ['title' => 'Sign up · ' . app_name(), 'form' => []]);
});

route('POST', '/register', function () {
    if (!feature_enabled('feature_registration')) {
        redirect('/login');
    }
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $name = $_POST['name'] ?? '';
    [$ok, $err, $uid] = register_user($email, $password, $name);
    if (!$ok) {
        flash($err, 'error');
        page('register', ['title' => 'Sign up · ' . app_name(), 'form' => ['email' => $email, 'name' => $name]]);
        return;
    }
    login_user((int)$uid);
    flash('Welcome to ' . app_name() . '!', 'success');
    // Honor a pending redirect (e.g. accepting an org invite via its link).
    $next = $_SESSION['login_next'] ?? url('/admin');
    unset($_SESSION['login_next']);
    if (!is_string($next) || strpos($next, '://') !== false) $next = url('/admin'); // local only
    header('Location: ' . $next);
    exit;
});

// ── Login ─────────────────────────────────────────────────────────────────
route('GET', '/login', function () {
    if (is_logged_in()) redirect('/admin');
    page('login', ['title' => 'Sign in · ' . app_name(), 'form' => []]);
});

route('POST', '/login', function () {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    [$ok, $err] = attempt_login($email, $password);
    if (!$ok) {
        flash($err, 'error');
        page('login', ['title' => 'Sign in · ' . app_name(), 'form' => ['email' => $email]]);
        return;
    }
    $next = $_SESSION['login_next'] ?? url('/admin');
    unset($_SESSION['login_next']);
    // Only allow local redirects
    if (!is_string($next) || strpos($next, '://') !== false) $next = url('/admin');
    header('Location: ' . $next);
    exit;
});

// ── Forgot / reset password ───────────────────────────────────────────────
route('GET', '/forgot-password', function () {
    page('forgot_password', ['title' => 'Reset password · ' . app_name(), 'sent' => false, 'link' => null]);
});
route('POST', '/forgot-password', function () {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $link = null;
    $user = $email ? DB::one("SELECT id FROM users WHERE email=?", [$email]) : null;
    if ($user) {
        $token = random_token(24);
        DB::insert("INSERT INTO password_resets(user_id, token, created_at, expires_at) VALUES(?,?,?,?)",
            [$user['id'], $token, now_ts(), now_ts() + 900]); // 15 min
        $url = abs_url('/reset-password/' . $token);
        $ok = send_mail($email, app_name() . ' — password reset',
            "We received a request to reset your password.\n\nOpen this link (valid 15 minutes):\n{$url}\n\nIf you didn't request this, ignore this email.");
        if (!$ok) $link = $url; // show on-screen when mail isn't available (dev / no SMTP)
    }
    // Always show the same message (don't leak which emails exist)
    page('forgot_password', ['title' => 'Reset password · ' . app_name(), 'sent' => true, 'link' => $link]);
});
route('GET', '/reset-password/{token}', function ($p) {
    $row = DB::one("SELECT * FROM password_resets WHERE token=?", [$p['token']]);
    $valid = $row && !$row['used_at'] && (int)$row['expires_at'] > now_ts();
    page('reset_password', ['title' => 'Set new password · ' . app_name(), 'token' => $p['token'], 'valid' => $valid]);
});
route('POST', '/reset-password/{token}', function ($p) {
    $row = DB::one("SELECT * FROM password_resets WHERE token=?", [$p['token']]);
    $valid = $row && !$row['used_at'] && (int)$row['expires_at'] > now_ts();
    if (!$valid) { page('reset_password', ['title'=>'Set new password','token'=>$p['token'],'valid'=>false]); return; }
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 6) {
        flash('Password must be at least 6 characters.', 'error');
        page('reset_password', ['title'=>'Set new password','token'=>$p['token'],'valid'=>true]); return;
    }
    DB::run("UPDATE users SET password_hash=?, failed_login_count=0, locked_until=NULL WHERE id=?", [hash_password($pw), $row['user_id']]);
    DB::run("UPDATE password_resets SET used_at=? WHERE id=?", [now_ts(), $row['id']]);
    flash('Password updated — you can sign in now.', 'success');
    redirect('/login');
});

// ── Logout ──────────────────────────────────────────────────────────────
route('GET', '/logout', function () {
    logout_user();
    flash('Signed out.', 'success');
    redirect('/');
});

// ── Admin dashboard is now defined in routes_quiz.php (Step 2) ───────────

// ── Join a quiz by code ───────────────────────────────────────────────────
route('GET', '/join', function () {
    page('join', ['title' => 'Take a quiz · ' . app_name(), 'bad' => false]);
});
route('POST', '/join', function () {
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    if ($code !== '') {
        $exists = DB::scalar("SELECT 1 FROM quizzes WHERE share_code=? AND is_published=1", [$code]);
        if ($exists) redirect('/q/' . $code);
        // Live-session join codes share the same box.
        $live = DB::scalar("SELECT 1 FROM live_sessions WHERE join_code=? AND status<>'ended'", [$code]);
        if ($live) redirect('/live/' . $code);
    }
    page('join', ['title' => 'Take a quiz · ' . app_name(), 'bad' => true]);
});

// Steps 2-6 will require these additional route files as they're built:
foreach (['routes_quiz.php', 'routes_take.php', 'routes_polls.php', 'routes_extras.php', 'routes_orgs.php', 'routes_live.php', 'routes_organizations.php'] as $rf) {
    $p = __DIR__ . '/' . $rf;
    if (is_file($p)) require $p;
}
