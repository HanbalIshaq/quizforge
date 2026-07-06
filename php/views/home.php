<?php /** Landing page. */ $feat = features_all(); ?>
<section class="text-center py-10 sm:py-16">
  <p class="inline-block text-xs uppercase tracking-wider bg-brand-50 text-brand-700 px-3 py-1 rounded-full mb-4">All-in-one assessment platform</p>
  <h1 class="text-3xl sm:text-5xl font-bold text-slate-900 mb-5 leading-tight">
    Quizzes, exams &amp; live polls<br class="hidden sm:block" />
    <span class="text-brand-700">that run on any host.</span>
  </h1>
  <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto mb-7">
    Auto-graded tests, anti-cheating exams, real-time polling, branded certificates and
    AI quiz generation — self-hostable on ordinary PHP + MySQL shared hosting.
  </p>
  <div class="flex flex-wrap justify-center gap-3">
    <?php if ($feat['feature_registration']): ?>
      <a href="<?= e(url('/register')) ?>" class="px-6 py-3 bg-brand-600 text-white rounded-lg hover:bg-brand-700 font-medium">Start free — no credit card</a>
    <?php endif; ?>
    <a href="<?= e(url('/join')) ?>" class="px-6 py-3 bg-white border border-slate-300 rounded-lg hover:bg-slate-100">I have a quiz code</a>
  </div>
</section>

<section class="mb-12">
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php
    $feats = [
      ['🧠', '30+ question types', 'MCQ, true/false, short/long answer, fill-blank, matching, ordering, drag-drop, image hotspot, rating, NPS, and full form fields.'],
      ['🎮', 'Live poll mode', 'Real-time sessions with a join code and live leaderboard — works on shared hosting via lightweight polling.'],
      ['🛡️', 'Anti-cheating', 'Tab-switch detection, copy/paste block, fullscreen lock, password gate, IP allow-list, AI camera proctoring.'],
      ['🤖', 'AI quiz generator', 'Paste any document and generate ready-to-use questions in seconds.'],
      ['🏆', 'Certificates', 'Auto-issued branded PDF certificates with a public verification URL.'],
      ['📊', 'Rich results', 'Per-question breakdown, bar charts, NPS, word clouds, CSV/Excel export.'],
    ];
    foreach ($feats as [$icon, $t, $d]): ?>
      <div class="bg-white border border-slate-200 rounded-lg p-5 hover:border-brand-300 transition">
        <div class="text-2xl mb-1" aria-hidden="true"><?= $icon ?></div>
        <h3 class="font-semibold text-slate-900 mb-1"><?= e($t) ?></h3>
        <p class="text-sm text-slate-600"><?= e($d) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>
