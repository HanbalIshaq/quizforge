<?php
/**
 * Multi-tenant organizations: teams that share quizzes, with roles + invites.
 *
 * Roles (org_members.role):
 *   owner  — created the org; full control incl. rename, branding, delete, all quizzes
 *   admin  — manage members + edit any org quiz
 *   member — create org quizzes; edit only the ones they created
 *
 * Active org context lives in $_SESSION['active_org_id'] (null = personal space).
 */

declare(strict_types=1);

const ORG_ROLES = ['owner', 'admin', 'member'];

function org_slugify(string $name): string
{
    $s = strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    if ($s === '') $s = 'org';
    // Ensure uniqueness.
    $base = substr($s, 0, 48);
    $slug = $base;
    for ($i = 2; DB::scalar("SELECT 1 FROM organizations WHERE slug=?", [$slug]); $i++) {
        $slug = $base . '-' . $i;
    }
    return $slug;
}

/** Create an org and make $userId its owner. Returns the new org id. */
function create_org(string $name, int $userId): int
{
    $name = trim($name) !== '' ? trim($name) : 'My organization';
    $now = now_ts();
    $orgId = DB::insert(
        "INSERT INTO organizations(name, slug, cert_org_name, created_at, created_by_user_id) VALUES(?,?,?,?,?)",
        [$name, org_slugify($name), $name, $now, $userId]
    );
    DB::run("INSERT INTO org_members(org_id, user_id, role, joined_at) VALUES(?,?,?,?)",
        [$orgId, $userId, 'owner', $now]);
    return $orgId;
}

/** Orgs the user belongs to, with their role, name-sorted. */
function user_orgs(int $userId): array
{
    return DB::all(
        "SELECT o.*, m.role AS my_role
         FROM organizations o JOIN org_members m ON m.org_id=o.id
         WHERE m.user_id=? ORDER BY o.name",
        [$userId]
    );
}

/** The user's role in an org, or null if not a member. */
function org_role(int $orgId, int $userId): ?string
{
    $r = DB::scalar("SELECT role FROM org_members WHERE org_id=? AND user_id=?", [$orgId, $userId]);
    return $r !== null ? (string)$r : null;
}

/** Load an org row (or null). */
function get_org(int $orgId): ?array
{
    return DB::one("SELECT * FROM organizations WHERE id=?", [$orgId]);
}

/** The active org row for this session, if any and still valid. */
function active_org(): ?array
{
    $oid = (int)($_SESSION['active_org_id'] ?? 0);
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$oid || !$uid) return null;
    if (org_role($oid, $uid) === null) { unset($_SESSION['active_org_id']); return null; }
    return get_org($oid);
}

/** Switch to an org (validated) or back to personal ($orgId = null/0). */
function set_active_org(?int $orgId): void
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    if (!$orgId) { unset($_SESSION['active_org_id']); return; }
    if ($uid && org_role($orgId, $uid) !== null) $_SESSION['active_org_id'] = $orgId;
}

/** Guard: current user must hold one of $roles in $orgId (else 404). */
function require_org_role(int $orgId, array $roles): array
{
    require_login();
    $uid = (int)$_SESSION['uid'];
    $role = org_role($orgId, $uid);
    $org = $role !== null ? get_org($orgId) : null;
    if (!$org || !in_array($role, $roles, true)) {
        http_response_code(404);
        page('error', ['title' => 'Not found', 'code' => 404, 'message' => 'Organization not found.']);
        exit;
    }
    $org['my_role'] = $role;
    return $org;
}

/** Members with their user details. */
function org_members(int $orgId): array
{
    return DB::all(
        "SELECT m.role, m.joined_at, u.id AS user_id, u.name, u.email
         FROM org_members m JOIN users u ON u.id=m.user_id
         WHERE m.org_id=?
         ORDER BY CASE m.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, u.name",
        [$orgId]
    );
}

/** Pending (unaccepted, unexpired) invites for an org. */
function org_pending_invites(int $orgId): array
{
    return DB::all(
        "SELECT * FROM org_invites WHERE org_id=? AND accepted_at IS NULL AND expires_at > ? ORDER BY created_at DESC",
        [$orgId, now_ts()]
    );
}

/** Create an invite; returns [token, existing?]. Reuses a live invite for the same email. */
function org_invite_create(int $orgId, string $email, string $role, int $byUserId): string
{
    $email = strtolower(trim($email));
    $role = in_array($role, ['admin', 'member'], true) ? $role : 'member';
    $now = now_ts();
    $live = DB::one("SELECT * FROM org_invites WHERE org_id=? AND email=? AND accepted_at IS NULL AND expires_at > ?",
        [$orgId, $email, $now]);
    if ($live) return $live['token'];
    $token = bin2hex(random_bytes(20));
    DB::insert(
        "INSERT INTO org_invites(org_id, email, role, token, invited_by_user_id, created_at, expires_at)
         VALUES(?,?,?,?,?,?,?)",
        [$orgId, $email, $role, $token, $byUserId, $now, $now + 14 * 86400]
    );
    return $token;
}

/** Look up a usable invite by token (null if missing/expired/accepted). */
function org_invite_by_token(string $token): ?array
{
    $inv = DB::one("SELECT * FROM org_invites WHERE token=?", [$token]);
    if (!$inv || $inv['accepted_at'] !== null || (int)$inv['expires_at'] < now_ts()) return null;
    return $inv;
}

/** Accept an invite for a user. Returns the org id, or null if invalid. */
function org_invite_accept(string $token, int $userId): ?int
{
    $inv = org_invite_by_token($token);
    if (!$inv) return null;
    $orgId = (int)$inv['org_id'];
    if (org_role($orgId, $userId) === null) {
        DB::run("INSERT INTO org_members(org_id, user_id, role, joined_at) VALUES(?,?,?,?)",
            [$orgId, $userId, $inv['role'], now_ts()]);
    }
    DB::run("UPDATE org_invites SET accepted_at=? WHERE id=?", [now_ts(), $inv['id']]);
    return $orgId;
}

/** Can $userId edit $quiz? Owner of the quiz, or owner/admin of its org. */
function org_can_edit_quiz(array $quiz, int $userId): bool
{
    if ((int)$quiz['user_id'] === $userId) return true;
    if (!empty($quiz['org_id'])) {
        $role = org_role((int)$quiz['org_id'], $userId);
        return in_array($role, ['owner', 'admin'], true);
    }
    return false;
}
