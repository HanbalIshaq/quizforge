<?php /** Site settings: feature flags + ad slots. Expects $features, $ads_enabled, $ads. */
$flagLabels = [
  'feature_registration' => 'Public sign-up (new users can register)',
  'feature_ai_quiz_gen'  => 'AI question generation (needs an API key in config)',
  'feature_certificates' => 'PDF certificates on pass',
  'feature_live_mode'    => 'Live poll sessions',
  'feature_polls'        => 'Polls & surveys',
  'feature_anti_cheat'   => 'Anti-cheating tools',
  'feature_exports'      => 'CSV / export buttons',
  'feature_billing'      => 'Billing (reserved)',
];
$adLabels = ['header'=>'Header (below the top menu)','footer'=>'Footer (above the footer)','sidebar'=>'Sidebar','quiz_top'=>'Top of the take-quiz page','results'=>'Results page'];
?>
<a href="<?= e(url('/admin/site')) ?>" class="text-sm text-slate-500 hover:text-brand-700">&larr; Site admin</a>
<h1 class="text-2xl font-bold mt-1 mb-5">Site settings</h1>

<form method="post" action="<?= e(url('/admin/site/settings')) ?>" class="space-y-6">
  <?= csrf_field() ?>

  <!-- Feature flags -->
  <section class="qf-card qf-card-pad">
    <h2 class="font-semibold mb-1">Features</h2>
    <p class="text-xs text-slate-500 mb-3">Turn platform capabilities on or off for everyone.</p>
    <div class="grid sm:grid-cols-2 gap-2">
      <?php foreach ($flagLabels as $flag => $label): ?>
        <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 cursor-pointer">
          <input type="checkbox" name="<?= e($flag) ?>" <?= !empty($features[$flag]) ? 'checked' : '' ?> />
          <span class="text-sm"><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Ads -->
  <section class="qf-card qf-card-pad">
    <div class="flex items-center justify-between mb-1">
      <h2 class="font-semibold">Advertisements</h2>
      <label class="flex items-center gap-2 text-sm cursor-pointer">
        <input type="checkbox" name="ads_enabled" <?= $ads_enabled ? 'checked' : '' ?> />
        <span>Enable ads site-wide</span>
      </label>
    </div>
    <p class="text-xs text-slate-500 mb-3">
      Paste your ad-network code (e.g. Google AdSense) into a slot below. A slot shows
      <b>only</b> when ads are enabled AND that slot has code — empty slots render nothing.
      Leave a slot blank to hide it.
    </p>
    <div class="space-y-3">
      <?php foreach ($adLabels as $slot => $label): ?>
        <div>
          <label class="qf-label"><?= e($label) ?> <span class="text-slate-400 font-normal">(ad_code_<?= e($slot) ?>)</span></label>
          <textarea name="ad_code_<?= e($slot) ?>" rows="2" class="qf-textarea font-mono text-xs" placeholder="<!-- paste ad code here, or leave empty -->"><?= e($ads[$slot] ?? '') ?></textarea>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-slate-400 mt-2">Ad code is inserted as-is (trusted admin HTML). Only super-admins can edit this.</p>
  </section>

  <button class="qf-btn qf-btn-primary">Save settings</button>
</form>
