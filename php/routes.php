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
$CSRF_EXEMPT_PREFIXES = ['/q/']; // public take-quiz endpoints (later steps)

// ── Home ────────────────────────────────────────────────────────────────
route('GET', '/', function () {
    if (is_logged_in()) redirect('/admin');
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
    redirect('/admin');
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

// ── Logout ──────────────────────────────────────────────────────────────
route('GET', '/logout', function () {
    logout_user();
    flash('Signed out.', 'success');
    redirect('/');
});

// ── Admin dashboard (Step 1 placeholder; real one in Step 2) ─────────────
route('GET', '/admin', function () {
    require_login();
    $u = current_user();
    page('admin_placeholder', ['title' => 'Dashboard · ' . app_name(), 'u' => $u]);
});

// ── Join (Step 1 placeholder; real one in Step 3) ─────────────────────────
route('GET', '/join', function () {
    page('join_placeholder', ['title' => 'Take a quiz · ' . app_name()]);
});

// Steps 2-6 will require these additional route files as they're built:
foreach (['routes_quiz.php', 'routes_take.php', 'routes_polls.php', 'routes_extras.php', 'routes_orgs.php'] as $rf) {
    $p = __DIR__ . '/' . $rf;
    if (is_file($p)) require $p;
}
