<?php /** Temporary join page — the real take-quiz flow arrives in Step 3. */ ?>
<div class="max-w-md mx-auto mt-6 sm:mt-10 bg-white border border-slate-200 rounded-xl p-6 sm:p-8 text-center shadow-sm">
  <h1 class="text-2xl font-bold mb-2">Join a quiz or live session</h1>
  <p class="text-sm text-slate-500 mb-6">Enter the code you were given.</p>
  <form method="post" action="<?= e(url('/join')) ?>" class="space-y-3" novalidate>
    <?= csrf_field() ?>
    <label for="join-code" class="sr-only">Quiz or session code</label>
    <input id="join-code" name="code" required autocomplete="off" autocapitalize="characters"
           autocorrect="off" spellcheck="false" maxlength="12"
           class="w-full text-center text-2xl font-mono tracking-widest uppercase px-4 py-3 border-2 border-slate-300 rounded-lg focus:border-brand-500 focus:ring-2 focus:ring-brand-500"
           placeholder="ABC123" />
    <button type="submit" class="w-full py-3 bg-brand-600 text-white rounded-lg hover:bg-brand-700 font-medium">Join</button>
  </form>
  <p class="mt-4 text-xs text-slate-400">Taking quizzes will be enabled in the next build step.</p>
</div>
