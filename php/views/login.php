<?php /** Sign-in — split-screen welcome layout. Expects $form. */ ?>
<div class="qf-auth max-w-4xl mx-auto mt-2 sm:mt-6">
  <!-- Brand panel (desktop only) -->
  <div class="qf-auth-brand">
    <div class="flex items-center gap-2 mb-8">
      <span class="inline-block w-9 h-9 rounded-lg bg-white/20 grid place-items-center font-bold text-lg"><?= e(mb_substr(app_name(),0,1)) ?></span>
      <span class="text-xl font-bold"><?= e(app_name()) ?></span>
    </div>
    <h2 class="text-3xl font-bold leading-tight mb-3">Welcome back.</h2>
    <p class="text-white/80 text-sm mb-2">Sign in to build quizzes, run live polls, and see your results roll in.</p>
    <div class="qf-auth-feature">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <p class="text-sm text-white/90">Auto-graded exams &amp; 30+ question types</p>
    </div>
    <div class="qf-auth-feature">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <p class="text-sm text-white/90">Live polls, surveys &amp; JotForm-style forms</p>
    </div>
    <div class="qf-auth-feature">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
      <p class="text-sm text-white/90">Anti-cheating, certificates &amp; AI generation</p>
    </div>
  </div>

  <!-- Form panel -->
  <div class="qf-auth-form">
    <div class="w-full max-w-sm mx-auto">
      <h1 class="text-2xl font-bold mb-1">Sign in</h1>
      <p class="text-sm text-slate-500 mb-6">Enter your details to continue.</p>

      <form method="post" action="<?= e(url('/login')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="qf-field">
          <label for="login-email" class="qf-label">Email</label>
          <input id="login-email" class="qf-input" type="email" name="email" value="<?= e($form['email'] ?? '') ?>"
                 required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false"
                 placeholder="you@example.com" />
        </div>
        <div class="qf-field">
          <div class="flex items-baseline justify-between mb-1">
            <label for="login-password" class="qf-label mb-0">Password</label>
            <a href="<?= e(url('/forgot-password')) ?>" class="text-xs text-brand-700 hover:underline">Forgot?</a>
          </div>
          <div class="qf-pw-wrap">
            <input id="login-password" class="qf-input" type="password" name="password" required autocomplete="current-password" placeholder="••••••••" />
            <button type="button" class="qf-pw-toggle" data-pw-toggle="login-password" aria-label="Show password">Show</button>
          </div>
        </div>
        <button type="submit" class="qf-btn qf-btn-primary qf-btn-lg qf-btn-block">Sign in</button>
      </form>

      <p class="mt-6 text-sm text-slate-600 text-center">
        No account? <a href="<?= e(url('/register')) ?>" class="text-brand-700 hover:underline font-semibold">Create one — free</a>
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
