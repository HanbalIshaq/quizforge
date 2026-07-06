<?php /** Reset password. Expects $token, $valid (bool). */ ?>
<div class="max-w-md mx-auto mt-6 sm:mt-10 qf-card qf-card-pad" style="padding:2rem">
  <?php if (!$valid): ?>
    <div class="text-center">
      <div class="text-3xl mb-2" aria-hidden="true">⏳</div>
      <h1 class="text-xl font-bold">Link expired or invalid</h1>
      <p class="text-sm text-slate-500 mt-2">This reset link is no longer valid. Request a new one.</p>
      <a href="<?= e(url('/forgot-password')) ?>" class="qf-btn qf-btn-primary qf-btn-block mt-5">Request a new link</a>
    </div>
  <?php else: ?>
    <h1 class="text-2xl font-bold mb-1">Set a new password</h1>
    <p class="text-sm text-slate-500 mb-5">Choose a strong password you'll remember.</p>
    <form method="post" action="<?= e(url('/reset-password/'.$token)) ?>" novalidate>
      <?= csrf_field() ?>
      <div class="qf-field">
        <label class="qf-label" for="rp-pw">New password</label>
        <div class="qf-pw-wrap">
          <input id="rp-pw" class="qf-input" type="password" name="password" required minlength="6" autocomplete="new-password" placeholder="At least 6 characters" />
          <button type="button" class="qf-pw-toggle" data-pw-toggle="rp-pw" aria-label="Show password">Show</button>
        </div>
      </div>
      <button class="qf-btn qf-btn-primary qf-btn-block">Update password</button>
    </form>
  <?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-pw-toggle]').forEach(function(b){b.addEventListener('click',function(){var i=document.getElementById(b.getAttribute('data-pw-toggle'));if(!i)return;var s=i.type==='password';i.type=s?'text':'password';b.textContent=s?'Hide':'Show';});});
</script>
