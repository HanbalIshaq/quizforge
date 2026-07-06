<?php
/**
 * Shared helpers: config, CSRF, flash messages, escaping, settings/flags,
 * URL building, and small utilities. Ported behaviour from the Flask app.
 */

declare(strict_types=1);

// ── Config ────────────────────────────────────────────────────────────────

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $path = __DIR__ . '/../config.php';
        $cfg = is_file($path) ? (require $path) : [];
    }
    return $cfg;
}

function cfg(string $key, $default = null)
{
    $c = config();
    return $c[$key] ?? $default;
}

/**
 * Product name + tagline. Configurable in config.php ('app_name' /
 * 'app_tagline') so the whole product can be rebranded with a one-line edit.
 * Defaults chosen for SEO: "Quizly" keeps the top keyword "quiz" intact.
 */
function app_name(): string
{
    return (string) cfg('app_name', 'Quizly');
}

function app_tagline(): string
{
    return (string) cfg('app_tagline', 'Online Quiz, Exam & Poll Maker');
}

function is_installed(): bool
{
    return is_file(__DIR__ . '/../config.php') && (bool) cfg('installed', false);
}

// ── Base URL / routing ──────────────────────────────────────────────────────

/**
 * The base path the app is mounted at (e.g. "" for domain root, "/quiz" for a
 * subfolder). Auto-detected from the script location.
 */
function base_path(): string
{
    static $bp = null;
    if ($bp === null) {
        // SCRIPT_NAME is like /subfolder/index.php -> base is /subfolder
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $bp = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($bp === '/') $bp = '';
    }
    return $bp;
}

/** Absolute site root URL (scheme + host + base path), no trailing slash. */
function base_url(): string
{
    $configured = cfg('base_url', '');
    if ($configured) {
        return rtrim($configured, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_path();
}

/** Build an app URL from a route path like "/admin" or "/q/ABC123". */
function url(string $path = '/'): string
{
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return base_path() . $path;
}

/** Absolute URL (for emails, share links, certificate verification). */
function abs_url(string $path = '/'): string
{
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return base_url() . $path;
}

/** Redirect to an app route and stop. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

// ── Escaping ────────────────────────────────────────────────────────────────

/** HTML-escape (the Jinja autoescape equivalent). Use everywhere output. */
function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Sessions / CSRF ──────────────────────────────────────────────────────────

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_path() ?: '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);
    session_name('qf_session');
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = random_token(32);
    }
    return $_SESSION['_csrf'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify CSRF on state-changing requests. Accepts token from POST field
 * `_csrf` or header X-CSRF-Token. Aborts 403 on mismatch.
 */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return;
    }
    $submitted = $_POST['_csrf']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf'] ?? '';
    if (!$expected || !is_string($submitted) || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo 'CSRF validation failed. Please go back, refresh, and try again.';
        exit;
    }
}

// ── Flash messages ────────────────────────────────────────────────────────

function flash(string $msg, string $category = 'info'): void
{
    $_SESSION['_flash'][] = ['cat' => $category, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// ── Site settings + feature flags ────────────────────────────────────────────

const FEATURE_DEFAULTS = [
    'feature_registration' => '1',
    'feature_ai_quiz_gen'  => '0',
    'feature_certificates' => '1',
    'feature_live_mode'    => '1',
    'feature_polls'        => '1',
    'feature_anti_cheat'   => '1',
    'feature_exports'      => '1',
    'feature_billing'      => '0',
];

function setting_get(string $key, ?string $default = null): ?string
{
    $row = DB::one("SELECT svalue FROM site_settings WHERE skey = ?", [$key]);
    if ($row && $row['svalue'] !== null) {
        return $row['svalue'];
    }
    return $default ?? (FEATURE_DEFAULTS[$key] ?? null);
}

function setting_set(string $key, string $value): void
{
    // Portable upsert: try update, else insert.
    $stmt = DB::run("UPDATE site_settings SET svalue = ? WHERE skey = ?", [$value, $key]);
    if ($stmt->rowCount() === 0) {
        // rowCount can be 0 on "no change" in MySQL too; guard with a select
        $exists = DB::scalar("SELECT 1 FROM site_settings WHERE skey = ?", [$key]);
        if (!$exists) {
            DB::run("INSERT INTO site_settings(skey, svalue) VALUES(?, ?)", [$key, $value]);
        }
    }
}

function feature_enabled(string $name): bool
{
    $v = setting_get($name, FEATURE_DEFAULTS[$name] ?? '0');
    return in_array($v, ['1', 'true', 'True', 'on', 'yes'], true);
}

function features_all(): array
{
    $out = [];
    foreach (array_keys(FEATURE_DEFAULTS) as $k) {
        $out[$k] = feature_enabled($k);
    }
    return $out;
}

// ── Small utilities ──────────────────────────────────────────────────────────

/** Format a unix timestamp for display. */
function fmt_ts($ts): string
{
    if (!$ts) return '';
    return date('Y-m-d H:i', (int)$ts);
}

/** Read a JSON column safely into a PHP value. */
function json_col($raw, $default = [])
{
    if ($raw === null || $raw === '') return $default;
    $v = json_decode($raw, true);
    return $v === null ? $default : $v;
}

/** Coerce a value to int, or return $default if impossible. */
function to_int($x, $default = null)
{
    if (is_int($x)) return $x;
    if (is_bool($x)) return $x ? 1 : 0;
    if (is_float($x)) return (int)$x;
    if (is_string($x) && trim($x) !== '' && is_numeric(trim($x))) return (int)$x;
    return $default;
}

/** Map to_int across a list, dropping non-numeric entries. */
function int_list($seq): array
{
    if (!is_array($seq)) return [];
    $out = [];
    foreach ($seq as $x) {
        $v = to_int($x, null);
        if ($v !== null) $out[] = $v;
    }
    return $out;
}

/** Render a view file with variables, returning the HTML string. */
function render(string $view, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../views/' . $view . '.php';
    return (string) ob_get_clean();
}

/** Render a full page (view wrapped in layout) and echo it. */
function page(string $view, array $vars = []): void
{
    $vars['_content_view'] = $view;
    $content = render($view, $vars);
    echo render('layout', array_merge($vars, ['content' => $content]));
}
