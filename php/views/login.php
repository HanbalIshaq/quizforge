<?php /** Sign-in form. Expects $form (may hold prior email). */ ?>
<div class="max-w-md mx-auto mt-4 sm:mt-8 bg-white border border-slate-200 rounded-xl p-5 sm:p-7 shadow-sm">
  <h1 class="text-2xl font-bold mb-1">Sign in</h1>
  <p class="text-sm text-slate-500 mb-5">Welcome back. Sign in to access your quizzes.</p>

  <form method="post" action="<?= e(url('/login')) ?>" class="space-y-4" novalidate>
    <?= csrf_field() ?>
    <div>
      <label for="login-email" class="block text-sm font-medium mb-1.5">Email</label>
      <input id="login-email" type="email" name="email" value="<?= e($form['email'] ?? '') ?>"
             required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false"
             placeholder="you@example.com"
             class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500" />
    </div>
    <div>
      <div class="flex items-baseline justify-between mb-1.5">
        <label for="login-password" class="block text-sm font-medium">Password</label>
        <a href="<?= e(url('/forgot-password')) ?>" class="text-xs text-brand-700 hover:underline">Forgot?</a>
      </div>
      <input id="login-password" type="password" name="password" required autocomplete="current-password"
             class="w-full px-3 py-2.5 border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500" />
    </div>
    <button type="submit" class="w-full py-2.5 bg-brand-600 text-white rounded-lg hover:bg-brand-700 font-medium">Sign in</button>
  </form>

  <p class="mt-5 text-sm text-slate-600 text-center">
    No account? <a href="<?= e(url('/register')) ?>" class="text-brand-700 hover:underline font-medium">Create one — free</a>
  </p>
</div>
