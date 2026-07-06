<?php /** Certificate verification. Expects $cert (row + quiz_title) or null. */ ?>
<div class="max-w-lg mx-auto mt-8">
  <?php if ($cert): ?>
    <div class="qf-card qf-card-pad text-center" style="padding:2rem;border-color:#a7f3d0">
      <div class="w-14 h-14 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center text-3xl mx-auto mb-3" aria-hidden="true">✓</div>
      <h1 class="text-xl font-bold text-emerald-700">Valid certificate</h1>
      <p class="text-sm text-slate-500 mt-1">This certificate is genuine and was issued by <?= e(app_name()) ?>.</p>
      <div class="text-left text-sm mt-5 border-t border-slate-100 pt-4 space-y-2">
        <div class="flex justify-between"><span class="text-slate-500">Recipient</span><span class="font-medium"><?= e($cert['recipient_name']) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">For</span><span class="font-medium text-right"><?= e($cert['quiz_title']) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Score</span><span class="font-medium"><?= round((float)$cert['percentage']) ?>%</span></div>
        <div class="flex justify-between"><span class="text-slate-500">Issued</span><span class="font-medium"><?= e(date('F j, Y', (int)$cert['issued_at'])) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Serial</span><span class="font-mono"><?= e($cert['serial']) ?></span></div>
      </div>
      <a href="<?= e(url('/cert/'.$cert['serial'].'.pdf')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm mt-5">Download PDF</a>
    </div>
  <?php else: ?>
    <div class="qf-card qf-card-pad text-center" style="padding:2rem;border-color:#fecaca">
      <div class="w-14 h-14 rounded-full bg-red-100 text-red-700 grid place-items-center text-3xl mx-auto mb-3" aria-hidden="true">✕</div>
      <h1 class="text-xl font-bold text-red-700">Certificate not found</h1>
      <p class="text-sm text-slate-500 mt-1">We couldn't find a certificate with that serial. Check the code and try again.</p>
    </div>
  <?php endif; ?>
</div>
