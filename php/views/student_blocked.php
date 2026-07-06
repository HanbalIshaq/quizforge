<?php /** Shown when a taker is blocked (IP allow-list or max attempts). Expects $quiz, $reason. */ ?>
<div class="max-w-md mx-auto mt-10 qf-card qf-card-pad text-center" style="padding:2rem;border-color:#fecaca">
  <div class="text-4xl mb-2" aria-hidden="true">🚫</div>
  <h1 class="text-xl font-bold"><?= e($quiz['title']) ?></h1>
  <p class="text-sm text-slate-600 mt-3"><?= e($reason) ?></p>
  <a href="<?= e(url('/')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm mt-5">Back to home</a>
</div>
