<?php /** Accept an org invite. Expects $invite (null if invalid), $org, $token. */ ?>
<div class="max-w-md mx-auto mt-6 sm:mt-10 qf-card qf-card-pad text-center" style="padding:2rem">
  <?php if (empty($invite)): ?>
    <div class="text-4xl mb-2" aria-hidden="true">⌛</div>
    <h1 class="text-xl font-bold mb-1">Invitation not found</h1>
    <p class="text-sm text-slate-500 mb-5">This invite link is invalid, has expired, or was already used.</p>
    <a href="<?= e(url('/admin/orgs')) ?>" class="qf-btn qf-btn-secondary">Go to organizations</a>
  <?php else: ?>
    <div class="text-4xl mb-2" aria-hidden="true">🎊</div>
    <h1 class="text-xl font-bold mb-1">Join <?= e($org['name'] ?? 'the team') ?></h1>
    <p class="text-sm text-slate-500 mb-5">You've been invited as <strong><?= e($invite['role']) ?></strong>.</p>
    <form method="post" action="<?= e(url('/org/invite/' . $token . '/accept')) ?>">
      <?= csrf_field() ?>
      <button class="qf-btn qf-btn-primary qf-btn-lg qf-btn-block">Accept invitation</button>
    </form>
  <?php endif; ?>
</div>
