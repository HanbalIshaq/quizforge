<?php /** Live join. Expects $session (row|null), optional $code, $error. */ ?>
<div class="max-w-md mx-auto mt-6 sm:mt-10 qf-card qf-card-pad text-center" style="padding:2rem">
  <div class="text-4xl mb-2" aria-hidden="true">🎉</div>
  <?php if (empty($session)): ?>
    <h1 class="text-2xl font-bold mb-1">Join live</h1>
    <p class="text-sm text-slate-500 mb-6">Enter the game PIN shown on the host's screen.</p>
    <?php if (!empty($error)): ?><div class="qf-alert qf-alert-error mb-4"><?= e($error) ?></div><?php endif; ?>
    <form method="get" action="<?= e(url('/live')) ?>" onsubmit="event.preventDefault();var c=this.code.value.trim().toUpperCase();if(c)location.href='<?= e(url('/live/')) ?>'+encodeURIComponent(c);" novalidate>
      <label for="live-code" class="sr-only">Game PIN</label>
      <input id="live-code" name="code" required autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" maxlength="8"
             class="qf-input text-center text-2xl font-mono tracking-widest uppercase mb-3" placeholder="ABC123" style="letter-spacing:.3em" />
      <button class="qf-btn qf-btn-primary qf-btn-lg qf-btn-block">Enter</button>
    </form>
  <?php else: ?>
    <h1 class="text-2xl font-bold mb-1">You're in!</h1>
    <p class="text-sm text-slate-500 mb-1">Game PIN</p>
    <p class="text-2xl font-mono font-bold tracking-widest text-brand-700 mb-5"><?= e($code) ?></p>
    <?php if (!empty($error)): ?><div class="qf-alert qf-alert-error mb-4"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/live/' . $code . '/join')) ?>" novalidate>
      <label for="live-name" class="sr-only">Your name</label>
      <input id="live-name" name="name" required maxlength="40" autocomplete="off"
             class="qf-input text-center text-lg mb-3" placeholder="Your nickname" autofocus />
      <button class="qf-btn qf-btn-primary qf-btn-lg qf-btn-block">Join</button>
    </form>
  <?php endif; ?>
</div>
<script>
var f=document.getElementById('live-code');
if(f) f.addEventListener('input',function(){this.value=this.value.toUpperCase();});
</script>
