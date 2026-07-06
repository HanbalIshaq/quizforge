<?php
/** Admin dashboard. Expects $u, $quizzes, $counts, $kindFilter. */
$km = kind_meta();
$accentBg = ['brand'=>'bg-brand-600','amber'=>'bg-amber-500','emerald'=>'bg-emerald-600','purple'=>'bg-purple-600'];
$accentText = ['brand'=>'text-brand-700','amber'=>'text-amber-600','emerald'=>'text-emerald-700','purple'=>'text-purple-700'];
?>
<div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold">Welcome back, <?= e($u['name'] ?: explode('@',$u['email'])[0]) ?></h1>
    <p class="text-sm text-slate-500 mt-1">Create a new assessment, or manage what you've built.</p>
  </div>
  <form method="post" action="<?= e(url('/admin/seed-demo')) ?>" onsubmit="return confirm('Load a set of demo quizzes (exam, poll, survey, form) with sample responses? You can delete them anytime.')">
    <?= csrf_field() ?>
    <button class="qf-btn qf-btn-secondary qf-btn-sm">✨ Load demo data</button>
  </form>
</div>

<?php if (($counts['all'] ?? 0) === 0): ?>
  <div class="qf-card qf-card-pad mb-6" style="background:linear-gradient(135deg,#eef2ff,#faf5ff);border-color:#e0e7ff">
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:justify-between">
      <div>
        <p class="font-semibold text-slate-900">👋 New here? Load demo data to explore instantly.</p>
        <p class="text-sm text-slate-600 mt-0.5">Creates a demo exam, poll, survey and form — each with sample responses so every dashboard is populated.</p>
      </div>
      <form method="post" action="<?= e(url('/admin/seed-demo')) ?>" class="shrink-0">
        <?= csrf_field() ?>
        <button class="qf-btn qf-btn-primary">✨ Load demo data</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- Content-type cards -->
<section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <?php foreach ($km as $kind => $m): ?>
    <div class="qf-card qf-card-pad">
      <div class="flex items-start justify-between mb-2">
        <div class="w-11 h-11 rounded-lg <?= $accentBg[$m['accent']] ?> text-white grid place-items-center text-xl" aria-hidden="true"><?= $m['icon'] ?></div>
        <span class="text-2xl font-bold <?= $accentText[$m['accent']] ?>"><?= (int)($counts[$kind] ?? 0) ?></span>
      </div>
      <p class="font-semibold mt-1"><?= e($m['label']) ?></p>
      <p class="text-xs text-slate-500 mt-1 mb-3 leading-relaxed"><?= e($m['desc']) ?></p>
      <form method="post" action="<?= e(url('/admin/quizzes/new')) ?>" class="flex gap-1.5">
        <?= csrf_field() ?>
        <input type="hidden" name="kind" value="<?= e($kind) ?>" />
        <input name="title" required placeholder="New <?= e($kind) ?> title"
               class="qf-input flex-1 min-w-0" style="padding:.45rem .6rem;font-size:.85rem" />
        <button class="qf-btn qf-btn-primary qf-btn-sm shrink-0">+ New</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<!-- Filter chips -->
<div class="flex flex-wrap gap-1.5 mb-4 text-sm">
  <?php
  $chips = ['all'=>'All','exam'=>'Exams','poll'=>'Polls','survey'=>'Surveys','form'=>'Forms'];
  foreach ($chips as $k => $label):
    $active = ($kindFilter === $k);
    $count = $k === 'all' ? ($counts['all'] ?? 0) : ($counts[$k] ?? 0);
  ?>
    <a href="<?= e(url('/admin' . ($k==='all'?'':'?kind='.$k))) ?>"
       class="px-3 py-1.5 rounded-full <?= $active ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>">
      <?= e($label) ?> (<?= (int)$count ?>)
    </a>
  <?php endforeach; ?>
</div>

<!-- Quiz list -->
<?php if ($quizzes): ?>
  <div class="qf-card">
    <div class="qf-table-wrap">
      <table class="w-full text-sm qf-sortable">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="px-4 py-2.5">Title</th>
            <th class="px-4 py-2.5">Type</th>
            <th class="px-4 py-2.5">Questions</th>
            <th class="px-4 py-2.5">Responses</th>
            <th class="px-4 py-2.5">Share code</th>
            <th class="px-4 py-2.5">Updated</th>
            <th class="px-4 py-2.5" data-no-sort></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($quizzes as $q): $m = $km[$q['kind']] ?? $km['exam']; ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-2.5 font-medium">
                <a href="<?= e(url('/admin/quizzes/'.$q['id'])) ?>" class="text-brand-700 hover:underline"><?= e($q['title']) ?></a>
                <?php if (!$q['is_published']): ?><span class="qf-badge qf-badge-warn ml-1">Draft</span><?php endif; ?>
              </td>
              <td class="px-4 py-2.5"><span class="qf-badge qf-badge-<?= $q['kind']==='exam'?'brand':($q['kind']==='poll'?'warn':($q['kind']==='survey'?'ok':'muted')) ?>"><?= e(ucfirst($q['kind'])) ?></span></td>
              <td class="px-4 py-2.5"><?= (int)$q['n_q'] ?></td>
              <td class="px-4 py-2.5"><?= (int)$q['n_a'] ?></td>
              <td class="px-4 py-2.5 font-mono text-xs"><?= e($q['share_code']) ?></td>
              <td class="px-4 py-2.5 text-slate-500 text-xs" data-sort="<?= (int)$q['updated_at'] ?>"><?= e(fmt_ts($q['updated_at'])) ?></td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap" data-sort="">
                <a href="<?= e(url('/admin/quizzes/'.$q['id'])) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">Edit</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="qf-card qf-card-pad text-center py-12">
    <p class="text-4xl mb-3" aria-hidden="true">📥</p>
    <p class="text-lg font-semibold text-slate-900 mb-1">Nothing here yet</p>
    <p class="text-sm text-slate-500">Use a card above to create your first <?= $kindFilter==='all'?'quiz, poll or survey':e($kindFilter) ?>.</p>
  </div>
<?php endif; ?>
