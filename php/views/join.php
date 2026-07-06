<?php /** Join by code. Expects $bad (bool). */ ?>
<div class="max-w-md mx-auto mt-6 sm:mt-10 qf-card qf-card-pad text-center" style="padding:2rem">
  <div class="text-4xl mb-2" aria-hidden="true">🎯</div>
  <h1 class="text-2xl font-bold mb-1">Join a quiz</h1>
  <p class="text-sm text-slate-500 mb-6">Enter the code you were given.</p>
  <?php if (!empty($bad)): ?>
    <div class="qf-alert qf-alert-error mb-4">That code doesn't match a published quiz. Check it and try again.</div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('/join')) ?>" novalidate>
    <?= csrf_field() ?>
    <label for="join-code" class="sr-only">Quiz code</label>
    <input id="join-code" name="code" required autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" maxlength="12"
           class="qf-input text-center text-2xl font-mono tracking-widest uppercase mb-3" placeholder="ABC123" style="letter-spacing:.3em" />
    <button class="qf-btn qf-btn-primary qf-btn-lg qf-btn-block">Start</button>
  </form>
</div>
<script>
document.getElementById('join-code').addEventListener('input',function(){this.value=this.value.toUpperCase();});
</script>
