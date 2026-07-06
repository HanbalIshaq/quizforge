<?php /** Sign-up — split-screen welcome layout. Expects $form. */ ?>
<div class="qf-auth max-w-4xl mx-auto mt-2 sm:mt-6">
  <!-- Brand panel (desktop only) -->
  <div class="qf-auth-brand">
    <div class="flex items-center gap-2 mb-8">
      <span class="inline-block w-9 h-9 rounded-lg bg-white/20 grid place-items-center font-bold text-lg"><?= e(mb_substr(app_name(),0,1)) ?></span>
      <span class="text-xl font-bold"><?= e(app_name()) ?></span>
    </div>
    <h2 class="text-3xl font-bold leading-tight mb-3">Create quizzes in minutes.</h2>
    <p class="text-white/80 text-sm mb-2">Free forever for individual use. No credit card. Set up in under a minute.</p>
    <div class="qf-auth-feature">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <p class="text-sm text-white/90">Build exams, polls, surveys &amp; forms</p>
    </div>
    <div class="qf-auth-feature">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <p class="text-sm text-white/90">Share a link or run a live session</p>
    </div>
    <div class="qf-auth-feature">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <p class="text-sm text-white/90">Instant auto-grading &amp; rich results</p>
    </div>
  </div>

  <!-- Form panel -->
  <div class="qf-auth-form">
    <div class="w-full max-w-sm mx-auto">
      <h1 class="text-2xl font-bold mb-1">Create your account</h1>
      <p class="text-sm text-slate-500 mb-6">It's free — no credit card required.</p>

      <form method="post" action="<?= e(url('/register')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="qf-field">
          <label for="reg-name" class="qf-label">Your name</label>
          <input id="reg-name" class="qf-input" type="text" name="name" value="<?= e($form['name'] ?? '') ?>"
                 autocomplete="name" autocapitalize="words" placeholder="Jane Doe" />
        </div>
        <div class="qf-field">
          <label for="reg-email" class="qf-label">Email</label>
          <input id="reg-email" class="qf-input" type="email" name="email" value="<?= e($form['email'] ?? '') ?>"
                 required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false"
                 placeholder="you@example.com" />
        </div>
        <div class="qf-field">
          <label for="reg-password" class="qf-label">Password</label>
          <div class="qf-pw-wrap">
            <input id="reg-password" class="qf-input" type="password" name="password" required minlength="6"
                   autocomplete="new-password" aria-describedby="reg-password-hint" placeholder="At least 6 characters" />
            <button type="button" class="qf-pw-toggle" data-pw-toggle="reg-password" aria-label="Show password">Show</button>
          </div>
          <p id="reg-password-hint" class="qf-hint">Use at least 6 characters.</p>
        </div>
        <button type="submit" class="qf-btn qf-btn-primary qf-btn-lg qf-btn-block">Create account</button>
      </form>

      <p class="mt-6 text-sm text-slate-600 text-center">
        Already registered? <a href="<?= e(url('/login')) ?>" class="text-brand-700 hover:underline font-semibold">Sign in</a>
      </p>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-pw-toggle]').forEach(function(btn){
  btn.addEventListener('click', function(){
    var inp = document.getElementById(btn.getAttribute('data-pw-toggle'));
    if(!inp) return;
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });
});
</script>
