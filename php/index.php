<?php
/**
 * Front controller / router for QuizForge (PHP edition).
 *
 * All requests (except real files) are routed here by .htaccess. Routes are
 * matched by method + path. Uses simple, dependency-free pattern matching.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0'); // errors are handled/shown via our own handler

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/helpers.php';

// ── Not installed yet? Send to the installer. ────────────────────────────
if (!is_installed()) {
    // base_path() gives the mount folder with forward slashes on all OSes.
    $bp = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    if ($bp === '' || $bp === '/') $bp = '';
    header('Location: ' . $bp . '/install.php');
    exit;
}

require __DIR__ . '/includes/schema.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grading.php';
require __DIR__ . '/includes/quiz.php';
require __DIR__ . '/includes/importers.php';
require __DIR__ . '/includes/pdf.php';
require __DIR__ . '/includes/certificates.php';
require __DIR__ . '/includes/ai.php';
require __DIR__ . '/includes/mailer.php';
require __DIR__ . '/includes/live.php';
require __DIR__ . '/includes/orgs.php';

// Boot DB + session
try {
    DB::boot(config());
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed. Check config.php. (' . e($e->getMessage()) . ')';
    exit;
}
start_session();

// Never let the browser cache dynamic HTML — otherwise the back button /
// bfcache can show a stale logged-out header ("Sign in") after login, or a
// logged-in header after logout. Assets are served by Apache directly (they
// don't hit this file), so this only affects app pages.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Apply any pending schema migrations (version-gated: a no-op once current).
try { run_migrations(); } catch (Throwable $e) { error_log('migrate: '.$e->getMessage()); }

// Self-heal orphaned sessions: if a uid is set but the user no longer exists
// (or is suspended), is_logged_in() clears the stale uid. Running it once here
// means every page — including the public header — reflects the true state.
if (!empty($_SESSION['uid'])) { is_logged_in(); }

// ── Work out the route path (works with or without mod_rewrite) ───────────
function route_path(): string
{
    // Prefer PATH_INFO (works via index.php/route without rewrite)
    $p = $_SERVER['PATH_INFO'] ?? '';
    if ($p === '') {
        // Derive from REQUEST_URI minus the base path and query string
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = explode('?', $uri, 2)[0];
        $base = base_path();
        if ($base && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }
        // strip a trailing /index.php
        $uri = preg_replace('#/index\.php#', '', $uri);
        $p = $uri;
    }
    $p = '/' . trim($p, '/');
    return $p === '/' ? '/' : rtrim($p, '/');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = route_path();

// ── Tiny router ───────────────────────────────────────────────────────────
$routes = [];
function route(string $method, string $pattern, callable $handler): void
{
    global $routes;
    $routes[] = [$method, $pattern, $handler];
}

/** Match "/q/{code}" style patterns; fills $params by name. */
function match_route(string $pattern, string $path, array &$params): bool
{
    $params = [];
    $regex = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';
    if (preg_match($regex, $path, $m)) {
        foreach ($m as $k => $v) {
            if (!is_int($k)) $params[$k] = $v;
        }
        return true;
    }
    return false;
}

// Load route definitions
require __DIR__ . '/routes.php';

// ── Dispatch ────────────────────────────────────────────────────────────────
try {
    foreach ($routes as [$m, $pattern, $handler]) {
        if ($m !== $method) continue;
        $params = [];
        if (match_route($pattern, $path, $params)) {
            // CSRF for state-changing requests (skip for public quiz endpoints,
            // which are marked by the route file adding them to $CSRF_EXEMPT).
            if ($method === 'POST') {
                global $CSRF_EXEMPT;
                $exempt = in_array($path, $CSRF_EXEMPT ?? [], true);
                // also exempt by prefix for /q/... public endpoints
                foreach (($CSRF_EXEMPT_PREFIXES ?? []) as $pre) {
                    if (strpos($path, $pre) === 0) $exempt = true;
                }
                if (!$exempt) csrf_check();
            }
            $handler($params);
            exit;
        }
    }
    // No match
    http_response_code(404);
    page('error', ['title' => 'Not found', 'code' => 404, 'message' => 'That page does not exist.']);
} catch (Throwable $e) {
    http_response_code(500);
    $errId = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log("QuizForge err_$errId: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $u = null;
    try { $u = current_user(); } catch (Throwable $ignored) {}
    $detail = ($u && !empty($u['is_super_admin'])) ? ($e->getMessage() . "\n\n" . $e->getTraceAsString()) : null;
    page('error', ['title' => 'Something went wrong', 'code' => 500,
        'message' => 'The server hit an unexpected error.', 'err_id' => $errId, 'detail' => $detail]);
}
