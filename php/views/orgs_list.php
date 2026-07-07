<?php /** My organizations. Expects $orgs (with my_role), $activeOrg. */ ?>
<div class="max-w-3xl mx-auto">
  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-2xl font-bold">Organizations</h1>
      <p class="text-sm text-slate-500">Teams that share quizzes, results and certificate branding.</p>
    </div>
    <a href="<?= e(url('/admin')) ?>" class="qf-btn qf-btn-ghost qf-btn-sm">← Dashboard</a>
  </div>

  <?php if ($orgs): ?>
    <div class="qf-card divide-y divide-slate-100 mb-6">
      <?php foreach ($orgs as $o): $isActive = $activeOrg && (int)$activeOrg['id'] === (int)$o['id']; ?>
        <div class="flex items-center justify-between gap-3 p-4">
          <div class="min-w-0">
            <a href="<?= e(url('/admin/orgs/' . $o['id'])) ?>" class="font-semibold text-brand-700 hover:underline"><?= e($o['name']) ?></a>
            <span class="qf-badge qf-badge-muted ml-1"><?= e(ucfirst($o['my_role'])) ?></span>
            <?php if ($isActive): ?><span class="qf-badge qf-badge-ok ml-1">Active</span><?php endif; ?>
          </div>
          <div class="flex gap-2 shrink-0">
            <?php if (!$isActive): ?>
              <form method="post" action="<?= e(url('/admin/orgs/switch')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="org_id" value="<?= (int)$o['id'] ?>">
                <input type="hidden" name="return" value="/admin/orgs/<?= (int)$o['id'] ?>">
                <button class="qf-btn qf-btn-secondary qf-btn-sm">Switch to</button>
              </form>
            <?php endif; ?>
            <a href="<?= e(url('/admin/orgs/' . $o['id'])) ?>" class="qf-btn qf-btn-ghost qf-btn-sm">Manage</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="qf-card qf-card-pad text-center py-10 mb-6">
      <p class="text-4xl mb-2" aria-hidden="true">🏢</p>
      <p class="font-semibold">You're not in any organization yet</p>
      <p class="text-sm text-slate-500">Create one to share quizzes with your team.</p>
    </div>
  <?php endif; ?>

  <div class="qf-card qf-card-pad">
    <h2 class="font-semibold mb-3">Create an organization</h2>
    <form method="post" action="<?= e(url('/admin/orgs')) ?>" class="flex flex-col sm:flex-row gap-2">
      <?= csrf_field() ?>
      <input name="name" required maxlength="200" class="qf-input flex-1" placeholder="e.g. Acme Training Team" />
      <button class="qf-btn qf-btn-primary">Create</button>
    </form>
  </div>
</div>
