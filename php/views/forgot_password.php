<?php /** Forgot password. Expects $sent (bool), $link (string|null). */ ?>
<div class="max-w-md mx-auto mt-6 sm:mt-10 qf-card qf-card-pad" style="padding:2rem">
  <h1 class="text-2xl font-bold mb-1">Reset your password</h1>
  <?php if ($sent): ?>
    <p class="text-sm text-slate-600 mt-3">If an account exists for that email, we've sent a reset link. It's valid for 15 minutes.</p>
    <?php if (!empty($link)): ?>
      <div class="qf-alert qf-alert-info mt-4 text-xs break-all">
        Email isn't configured on this server, so here's your reset link:<br>
        <a class="text-brand-700 underline" href="<?= e($link) ?>"><?= e($link) ?></a>
      </div>
    <?php endif; ?>
    <a href="<?= e(url('/login')) ?>" class="qf-btn qf-btn-secondary qf-btn-block mt-5">Back to sign in</a>
  <?php else: ?>
    <p class="text-sm text-slate-500 mb-5">Enter your email and we'll send a link to reset it.</p>
    <form method="post" action="<?= e(url('/forgot-password')) ?>">
      <?= csrf_field() ?>
      <div class="qf-field">
        <label class="qf-label" for="fp-email">Email</label>
        <input id="fp-email" class="qf-input" type="email" name="email" required autocomplete="email" inputmode="email" autocapitalize="off" placeholder="you@example.com" />
      </div>
      <button class="qf-btn qf-btn-primary qf-btn-block">Send reset link</button>
    </form>
    <p class="mt-5 text-sm text-slate-600 text-center"><a href="<?= e(url('/login')) ?>" class="text-brand-700 hover:underline">Back to sign in</a></p>
  <?php endif; ?>
</div>
