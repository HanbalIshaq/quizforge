<?php /** Password gate. Expects $quiz, $bad. */ ?>
<div class="max-w-md mx-auto mt-8 qf-card qf-card-pad" style="padding:2rem">
  <div class="text-center mb-4">
    <div class="text-3xl mb-1" aria-hidden="true">🔒</div>
    <h1 class="text-xl font-bold"><?= e($quiz['title']) ?></h1>
    <p class="text-sm text-slate-500 mt-1">This quiz is password-protected.</p>
  </div>
  <?php if (!empty($bad)): ?><div class="qf-alert qf-alert-error mb-3">Incorrect password.</div><?php endif; ?>
  <form method="post" action="<?= e(url('/q/'.$quiz['share_code'])) ?>">
    <div class="qf-field">
      <label class="qf-label" for="qp">Password</label>
      <input id="qp" class="qf-input" type="password" name="__password" required autofocus />
    </div>
    <button class="qf-btn qf-btn-primary qf-btn-block">Unlock</button>
  </form>
</div>
