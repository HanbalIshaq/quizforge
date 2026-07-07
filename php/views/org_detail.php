<?php /** Org home. Expects $org (with my_role), $members, $invites, $quizzes, $canManage, $isOwner. */ ?>
<div class="max-w-4xl mx-auto">
  <div class="flex items-center justify-between gap-3 mb-5">
    <div class="min-w-0">
      <a href="<?= e(url('/admin/orgs')) ?>" class="text-sm text-slate-400 hover:text-brand-700">← Organizations</a>
      <h1 class="text-2xl font-bold truncate"><?= e($org['name']) ?></h1>
      <p class="text-sm text-slate-500">Your role: <span class="qf-badge qf-badge-muted"><?= e(ucfirst($org['my_role'])) ?></span></p>
    </div>
    <form method="post" action="<?= e(url('/admin/orgs/switch')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="org_id" value="<?= (int)$org['id'] ?>">
      <input type="hidden" name="return" value="/admin">
      <button class="qf-btn qf-btn-primary qf-btn-sm">Work in this org →</button>
    </form>
  </div>

  <div class="grid gap-5 md:grid-cols-2">
    <!-- Members -->
    <div class="qf-card qf-card-pad">
      <h2 class="font-semibold mb-3">Members <span class="text-slate-400 font-normal">(<?= count($members) ?>)</span></h2>
      <div class="divide-y divide-slate-100">
        <?php foreach ($members as $m): ?>
          <div class="flex items-center justify-between gap-2 py-2">
            <div class="min-w-0">
              <p class="font-medium truncate"><?= e($m['name'] ?: $m['email']) ?></p>
              <p class="text-xs text-slate-400 truncate"><?= e($m['email']) ?></p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
              <span class="qf-badge qf-badge-<?= $m['role']==='owner'?'brand':($m['role']==='admin'?'ok':'muted') ?>"><?= e(ucfirst($m['role'])) ?></span>
              <?php if ($isOwner && $m['role'] !== 'owner'): ?>
                <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/members/' . $m['user_id'] . '/role')) ?>">
                  <?= csrf_field() ?>
                  <select name="role" onchange="this.form.submit()" class="qf-input qf-input-sm py-1 text-xs">
                    <option value="member" <?= $m['role']==='member'?'selected':'' ?>>Member</option>
                    <option value="admin"  <?= $m['role']==='admin'?'selected':'' ?>>Admin</option>
                  </select>
                </form>
              <?php endif; ?>
              <?php if ($canManage && $m['role'] !== 'owner'): ?>
                <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/members/' . $m['user_id'] . '/remove')) ?>" onsubmit="return confirm('Remove this member?')">
                  <?= csrf_field() ?>
                  <button class="qf-btn qf-btn-ghost qf-btn-sm text-red-600" title="Remove">✕</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($canManage): ?>
        <h3 class="font-semibold mt-5 mb-2 text-sm">Invite someone</h3>
        <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/invite')) ?>" class="flex flex-col gap-2">
          <?= csrf_field() ?>
          <input type="email" name="email" required class="qf-input" placeholder="teammate@example.com" />
          <div class="flex gap-2">
            <select name="role" class="qf-input flex-1">
              <option value="member">Member — create &amp; manage own quizzes</option>
              <option value="admin">Admin — manage all quizzes &amp; members</option>
            </select>
            <button class="qf-btn qf-btn-primary">Invite</button>
          </div>
        </form>

        <?php if ($invites): ?>
          <p class="text-xs uppercase tracking-wide text-slate-400 mt-4 mb-1">Pending invites</p>
          <div class="divide-y divide-slate-100">
            <?php foreach ($invites as $inv): ?>
              <div class="flex items-center justify-between gap-2 py-1.5 text-sm">
                <span class="truncate"><?= e($inv['email']) ?> · <span class="text-slate-400"><?= e($inv['role']) ?></span></span>
                <div class="flex items-center gap-2 shrink-0">
                  <button type="button" class="qf-btn qf-btn-ghost qf-btn-sm" data-copy="<?= e(abs_url('/org/invite/' . $inv['token'])) ?>">Copy link</button>
                  <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/invite/' . $inv['id'] . '/revoke')) ?>">
                    <?= csrf_field() ?>
                    <button class="qf-btn qf-btn-ghost qf-btn-sm text-red-600">Revoke</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Settings + quizzes -->
    <div class="space-y-5">
      <?php if ($canManage): ?>
        <div class="qf-card qf-card-pad">
          <h2 class="font-semibold mb-3">Settings</h2>
          <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/settings')) ?>" class="space-y-3">
            <?= csrf_field() ?>
            <div>
              <label class="block text-sm font-medium mb-1">Organization name</label>
              <input name="name" value="<?= e($org['name']) ?>" maxlength="200" class="qf-input" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Certificate name 🏆</label>
              <input name="cert_org_name" value="<?= e($org['cert_org_name'] ?? '') ?>" maxlength="200" class="qf-input" placeholder="<?= e($org['name']) ?>" />
              <p class="text-xs text-slate-400 mt-1">Printed across the top of certificates for this org's exams. Defaults to the org name.</p>
            </div>
            <button class="qf-btn qf-btn-primary qf-btn-sm">Save settings</button>
          </form>
        </div>
      <?php endif; ?>

      <div class="qf-card qf-card-pad">
        <h2 class="font-semibold mb-3">Quizzes <span class="text-slate-400 font-normal">(<?= count($quizzes) ?>)</span></h2>
        <?php if ($quizzes): ?>
          <div class="divide-y divide-slate-100">
            <?php foreach ($quizzes as $q): ?>
              <div class="flex items-center justify-between gap-2 py-2 text-sm">
                <a href="<?= e(url('/admin/quizzes/' . $q['id'])) ?>" class="text-brand-700 hover:underline truncate"><?= e($q['title']) ?></a>
                <span class="text-slate-400 shrink-0"><?= (int)$q['n_q'] ?> Q · <?= e($q['owner_name'] ?? '') ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-sm text-slate-500">No quizzes yet. Switch to this org, then create one from the dashboard.</p>
        <?php endif; ?>
      </div>

      <!-- Danger zone -->
      <div class="qf-card qf-card-pad">
        <?php if ($isOwner): ?>
          <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/delete')) ?>" onsubmit="return confirm('Delete this organization? Its quizzes move back to their individual owners.')">
            <?= csrf_field() ?>
            <button class="qf-btn qf-btn-danger qf-btn-sm">Delete organization</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= e(url('/admin/orgs/' . $org['id'] . '/leave')) ?>" onsubmit="return confirm('Leave this organization?')">
            <?= csrf_field() ?>
            <button class="qf-btn qf-btn-ghost qf-btn-sm text-red-600">Leave organization</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
