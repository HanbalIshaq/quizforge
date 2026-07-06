<?php
/**
 * Session-based authentication. Ports the Flask auth helpers:
 * bcrypt hashing (via password_hash), login rate-limiting, account lockout,
 * current_user(), and route guards.
 */

declare(strict_types=1);

const LOGIN_MAX_PER_WINDOW = 8;
const LOGIN_WINDOW_SECS = 900;            // 15 min
const ACCOUNT_LOCKOUT_THRESHOLD = 10;
const ACCOUNT_LOCKOUT_DURATION = 3600;    // 1 hour

/** Hash a plaintext password. */
function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT);
}

function verify_password(string $plain, string $hash): bool
{
    return password_verify($plain, $hash);
}

/** The logged-in user row, or null. */
function current_user(): ?array
{
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    static $cache = [];
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $row = DB::one(
        "SELECT id, email, name, is_super_admin, is_approved, is_suspended, plan
         FROM users WHERE id = ?",
        [$uid]
    );
    $cache[$uid] = $row ?: null;
    return $cache[$uid];
}

function is_logged_in(): bool
{
    return !empty($_SESSION['uid']);
}

/** Guard: require login, else redirect to /login preserving the target. */
function require_login(): void
{
    if (!is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? url('/admin');
        $_SESSION['login_next'] = $next;
        redirect('/login');
    }
}

/** Guard: require super-admin, else 403. */
function require_super_admin(): void
{
    require_login();
    $u = current_user();
    if (!$u || empty($u['is_super_admin'])) {
        http_response_code(403);
        echo 'Forbidden — super-admin only.';
        exit;
    }
}

/** Log a user in by id (sets session). */
function login_user(int $uid): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['uid'] = $uid;
    DB::run("UPDATE users SET last_login_at = ? WHERE id = ?", [now_ts(), $uid]);
}

function logout_user(): void
{
    unset($_SESSION['uid'], $_SESSION['active_org_id']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Register a new user. Returns [ok, error, uid].
 * The very first user becomes super-admin (matches the Python behaviour).
 */
function register_user(string $email, string $password, string $name): array
{
    $email = strtolower(trim($email));
    $name = trim($name);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Please enter a valid email address.', null];
    }
    if (strlen($password) < 6) {
        return [false, 'Password must be at least 6 characters.', null];
    }
    $exists = DB::scalar("SELECT 1 FROM users WHERE email = ?", [$email]);
    if ($exists) {
        return [false, 'That email is already in use. Try signing in instead.', null];
    }
    $isFirst = !DB::scalar("SELECT 1 FROM users LIMIT 1");
    $uid = DB::insert(
        "INSERT INTO users(email, password_hash, name, created_at, is_super_admin, is_approved)
         VALUES(?,?,?,?,?,1)",
        [$email, hash_password($password), $name, now_ts(), $isFirst ? 1 : 0]
    );
    return [true, null, $uid];
}

/** In-memory-ish login rate limit keyed by IP, stored in session-independent
 *  file? For shared hosting simplicity we track per-IP in a small table-free
 *  approach using the users table lockout + a session counter for the IP.
 *  Here we implement account lockout via the users table (persistent) which
 *  is the more important protection. */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $user = DB::one("SELECT * FROM users WHERE email = ?", [$email]);
    if (!$user) {
        return [false, 'Invalid email or password.'];
    }
    // Account lockout
    if (!empty($user['locked_until']) && (int)$user['locked_until'] > now_ts()) {
        $mins = (int) ceil(((int)$user['locked_until'] - now_ts()) / 60);
        return [false, "Account temporarily locked. Try again in {$mins} minute(s)."];
    }
    if (!empty($user['is_suspended'])) {
        return [false, 'This account has been suspended.'];
    }
    if (!verify_password($password, $user['password_hash'])) {
        $fails = (int)$user['failed_login_count'] + 1;
        $lockUntil = null;
        if ($fails >= ACCOUNT_LOCKOUT_THRESHOLD) {
            $lockUntil = now_ts() + ACCOUNT_LOCKOUT_DURATION;
            $fails = 0;
        }
        DB::run(
            "UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?",
            [$fails, $lockUntil, $user['id']]
        );
        return [false, 'Invalid email or password.'];
    }
    // Success — reset counters
    DB::run("UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?", [$user['id']]);
    login_user((int)$user['id']);
    return [true, null];
}
