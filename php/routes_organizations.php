<?php
/**
 * Multi-tenant organization management (teams that share quizzes).
 * Site-wide admin lives in routes_orgs.php (/admin/site/*); this is the
 * per-team feature under /admin/orgs/* plus public invite acceptance at
 * /org/invite/{token}.
 */

declare(strict_types=1);

// ── List my orgs + create ───────────────────────────────────────────────────
route('GET', '/admin/orgs', function () {
    require_login();
    $uid = (int)$_SESSION['uid'];
    page('orgs_list', [
        'title'     => 'Organizations · ' . app_name(),
        'orgs'      => user_orgs($uid),
        'activeOrg' => active_org(),
    ]);
});

route('POST', '/admin/orgs', function () {
    require_login();
    $uid = (int)$_SESSION['uid'];
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { flash('Please enter an organization name.', 'error'); redirect('/admin/orgs'); }
    $orgId = create_org(mb_substr($name, 0, 200), $uid);
    set_active_org($orgId);
    flash('Organization "' . $name . '" created — you are the owner.', 'success');
    redirect('/admin/orgs/' . $orgId);
});

// ── Switch active workspace (org or personal) ────────────────────────────────
route('POST', '/admin/orgs/switch', function () {
    require_login();
    $raw = trim((string)($_POST['org_id'] ?? ''));
    set_active_org($raw === '' ? null : (int)$raw);
    // return must be a local route path (redirect() prepends the base path).
    $return = (string)($_POST['return'] ?? '/admin');
    if ($return === '' || $return[0] !== '/' || strpos($return, '//') === 0) $return = '/admin';
    redirect($return);
});

// ── Org home: members, invites, settings, quizzes ────────────────────────────
route('GET', '/admin/orgs/{id}', function ($p) {
    $org = require_org_role((int)$p['id'], ORG_ROLES);
    $oid = (int)$org['id'];
    $quizzes = DB::all(
        "SELECT q.*, u.name AS owner_name,
                (SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) AS n_q
         FROM quizzes q LEFT JOIN users u ON u.id=q.user_id
         WHERE q.org_id=? ORDER BY q.updated_at DESC", [$oid]);
    page('org_detail', [
        'title'    => $org['name'] . ' · ' . app_name(),
        'org'      => $org,
        'members'  => org_members($oid),
        'invites'  => org_pending_invites($oid),
        'quizzes'  => $quizzes,
        'canManage'=> in_array($org['my_role'], ['owner', 'admin'], true),
        'isOwner'  => $org['my_role'] === 'owner',
    ]);
});

// ── Settings: rename + certificate branding (owner/admin) ────────────────────
route('POST', '/admin/orgs/{id}/settings', function ($p) {
    $org = require_org_role((int)$p['id'], ['owner', 'admin']);
    $name = trim($_POST['name'] ?? $org['name']);
    if ($name === '') $name = $org['name'];
    $certName = trim($_POST['cert_org_name'] ?? '');
    DB::run("UPDATE organizations SET name=?, cert_org_name=? WHERE id=?",
        [mb_substr($name, 0, 200), mb_substr($certName, 0, 200), $org['id']]);
    flash('Organization settings saved.', 'success');
    redirect('/admin/orgs/' . $org['id']);
});

// ── Invite a member (owner/admin) ────────────────────────────────────────────
route('POST', '/admin/orgs/{id}/invite', function ($p) {
    $org = require_org_role((int)$p['id'], ['owner', 'admin']);
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role  = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Please enter a valid email address.', 'error');
        redirect('/admin/orgs/' . $org['id']);
    }
    $token = org_invite_create((int)$org['id'], $email, $role, (int)$_SESSION['uid']);
    $url = abs_url('/org/invite/' . $token);
    $sent = send_mail($email, 'You are invited to join ' . $org['name'] . ' on ' . app_name(),
        "You have been invited to join the \"{$org['name']}\" team on " . app_name() . " as {$role}.\n\n"
        . "Accept your invite (valid 14 days):\n{$url}\n");
    if ($sent) flash('Invite emailed to ' . $email . '.', 'success');
    else       flash('Invite created. Share this link with ' . $email . ': ' . $url, 'info');
    redirect('/admin/orgs/' . $org['id']);
});

// ── Revoke a pending invite (owner/admin) ────────────────────────────────────
route('POST', '/admin/orgs/{id}/invite/{iid}/revoke', function ($p) {
    $org = require_org_role((int)$p['id'], ['owner', 'admin']);
    DB::run("DELETE FROM org_invites WHERE id=? AND org_id=? AND accepted_at IS NULL",
        [(int)$p['iid'], $org['id']]);
    flash('Invite revoked.', 'success');
    redirect('/admin/orgs/' . $org['id']);
});

// ── Change a member's role (owner only) ──────────────────────────────────────
route('POST', '/admin/orgs/{id}/members/{uid}/role', function ($p) {
    $org = require_org_role((int)$p['id'], ['owner']);
    $targetUid = (int)$p['uid'];
    $role = in_array($_POST['role'] ?? '', ['admin', 'member'], true) ? $_POST['role'] : 'member';
    // Never demote the org's sole/owner account via this route.
    $targetRole = org_role((int)$org['id'], $targetUid);
    if ($targetRole === 'owner') {
        flash('The owner role cannot be changed here.', 'error');
        redirect('/admin/orgs/' . $org['id']);
    }
    DB::run("UPDATE org_members SET role=? WHERE org_id=? AND user_id=?", [$role, $org['id'], $targetUid]);
    flash('Role updated.', 'success');
    redirect('/admin/orgs/' . $org['id']);
});

// ── Remove a member (owner/admin; can't remove the owner) ────────────────────
route('POST', '/admin/orgs/{id}/members/{uid}/remove', function ($p) {
    $org = require_org_role((int)$p['id'], ['owner', 'admin']);
    $targetUid = (int)$p['uid'];
    if (org_role((int)$org['id'], $targetUid) === 'owner') {
        flash('You cannot remove the owner.', 'error');
        redirect('/admin/orgs/' . $org['id']);
    }
    DB::run("DELETE FROM org_members WHERE org_id=? AND user_id=?", [$org['id'], $targetUid]);
    flash('Member removed. Their quizzes stay with the organization.', 'success');
    redirect('/admin/orgs/' . $org['id']);
});

// ── Leave an org (any non-owner member) ──────────────────────────────────────
route('POST', '/admin/orgs/{id}/leave', function ($p) {
    $org = require_org_role((int)$p['id'], ORG_ROLES);
    if ($org['my_role'] === 'owner') {
        flash('The owner cannot leave. Transfer ownership or delete the organization.', 'error');
        redirect('/admin/orgs/' . $org['id']);
    }
    DB::run("DELETE FROM org_members WHERE org_id=? AND user_id=?", [$org['id'], (int)$_SESSION['uid']]);
    if ((int)($_SESSION['active_org_id'] ?? 0) === (int)$org['id']) set_active_org(null);
    flash('You left ' . $org['name'] . '.', 'success');
    redirect('/admin/orgs');
});

// ── Delete an org (owner only) — quizzes revert to personal ──────────────────
route('POST', '/admin/orgs/{id}/delete', function ($p) {
    $org = require_org_role((int)$p['id'], ['owner']);
    // Detach quizzes back to their individual owners rather than deleting them.
    DB::run("UPDATE quizzes SET org_id=NULL WHERE org_id=?", [$org['id']]);
    DB::run("DELETE FROM org_invites WHERE org_id=?", [$org['id']]);
    DB::run("DELETE FROM org_members WHERE org_id=?", [$org['id']]);
    DB::run("DELETE FROM organizations WHERE id=?", [$org['id']]);
    if ((int)($_SESSION['active_org_id'] ?? 0) === (int)$org['id']) set_active_org(null);
    flash('Organization deleted. Its quizzes moved back to their owners.', 'success');
    redirect('/admin/orgs');
});

// ── Accept an invite (public link; must be logged in) ────────────────────────
route('GET', '/org/invite/{token}', function ($p) {
    $inv = org_invite_by_token($p['token']);
    if (!$inv) {
        page('org_invite', ['title' => 'Invite', 'invite' => null]);
        return;
    }
    $org = get_org((int)$inv['org_id']);
    if (!is_logged_in()) {
        // Bounce through login/registration, then return here.
        $_SESSION['login_next'] = url('/org/invite/' . $p['token']);
        flash('Sign in (or create an account) to accept your invitation to ' . ($org['name'] ?? 'the team') . '.', 'info');
        redirect('/login');
    }
    page('org_invite', ['title' => 'Join ' . ($org['name'] ?? ''), 'invite' => $inv, 'org' => $org, 'token' => $p['token']]);
});

route('POST', '/org/invite/{token}/accept', function ($p) {
    require_login();
    $orgId = org_invite_accept($p['token'], (int)$_SESSION['uid']);
    if (!$orgId) { flash('That invitation is no longer valid.', 'error'); redirect('/admin/orgs'); }
    set_active_org($orgId);
    $org = get_org($orgId);
    flash('You joined ' . ($org['name'] ?? 'the team') . '.', 'success');
    redirect('/admin/orgs/' . $orgId);
});
