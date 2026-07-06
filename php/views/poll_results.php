<?php
/** Aggregate results dashboard for polls & surveys. Expects $quiz, $agg, $respCount. */
$maxWord = 0;
foreach ($agg as $s) { if (($s['kind'] ?? '')==='text') { foreach (($s['words']??[]) as $c) $maxWord = max($maxWord, $c); } }
?>
<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-5">
  <div class="min-w-0">
    <a href="<?= e(url('/admin/quizzes/'.$quiz['id'])) ?>" class="text-sm text-slate-500 hover:text-brand-700">&larr; Edit</a>
    <h1 class="text-2xl font-bold mt-1 break-words">Results: <?= e($quiz['title']) ?></h1>
    <p class="text-sm text-slate-500"><?= (int)$respCount ?> response(s) · live aggregate</p>
  </div>
  <div class="flex flex-wrap gap-2 shrink-0">
    <button type="button" class="qf-btn qf-btn-secondary qf-btn-sm" data-copy="<?= e(abs_url('/q/'.$quiz['share_code'])) ?>">Copy share link</button>
    <a href="<?= e(url('/admin/quizzes/'.$quiz['id'].'/export.csv')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">Export CSV</a>
  </div>
</div>

<?php if ($respCount === 0): ?>
  <div class="qf-card qf-card-pad text-center py-12 text-slate-500">
    <p class="text-4xl mb-3">📊</p>
    <p class="text-lg font-semibold text-slate-900 mb-1">No responses yet</p>
    <p class="text-sm">Share <span class="font-mono bg-slate-100 px-2 py-0.5 rounded"><?= e($quiz['share_code']) ?></span> to start collecting.</p>
  </div>
<?php else: ?>
  <div class="space-y-4">
    <?php $qn=0; foreach ($agg as $s): $q=$s['q']; $qn++; ?>
      <div class="qf-card qf-card-pad">
        <div class="text-xs text-slate-500 mb-1">Q<?= $qn ?> · <?= e(str_replace('_',' ',$q['type'])) ?> · <?= (int)$s['total'] ?> response(s)</div>
        <div class="font-medium mb-3"><?= e($q['text']) ?></div>

        <?php if ($s['kind']==='choice'): $tot=max(1,array_sum($s['counts'])); ?>
          <div class="space-y-2">
            <?php foreach ($q['options'] as $oi=>$opt): $c=$s['counts'][$oi]??0; $pct=round($c/$tot*100); ?>
              <div>
                <div class="flex justify-between text-sm mb-0.5"><span><?= e($opt) ?></span><span class="text-slate-500"><?= $c ?> · <?= $pct ?>%</span></div>
                <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-brand-500 rounded-full" style="width:<?= $pct ?>%"></div></div>
              </div>
            <?php endforeach; ?>
          </div>

        <?php elseif ($s['kind']==='rating'): ?>
          <div class="flex items-center gap-4">
            <div class="text-4xl font-bold text-amber-500"><?= $s['avg'] ?><span class="text-lg text-slate-400">/5</span></div>
            <div class="flex-1 space-y-1">
              <?php for($i=5;$i>=1;$i--): $c=$s['dist'][$i]??0; $pct=$s['total']?round($c/$s['total']*100):0; ?>
                <div class="flex items-center gap-2 text-xs"><span class="w-8 text-slate-500"><?= $i ?>★</span>
                  <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden"><div class="h-full bg-amber-400" style="width:<?= $pct ?>%"></div></div>
                  <span class="w-8 text-right text-slate-500"><?= $c ?></span></div>
              <?php endfor; ?>
            </div>
          </div>

        <?php elseif ($s['kind']==='nps'): ?>
          <div class="flex items-center gap-6 flex-wrap">
            <div class="text-center"><div class="text-4xl font-bold <?= $s['nps']>=0?'text-emerald-600':'text-red-600' ?>"><?= $s['nps'] ?></div><div class="text-xs text-slate-500">NPS score</div></div>
            <div class="text-sm space-y-1">
              <div><span class="qf-badge qf-badge-ok">Promoters</span> <?= $s['promoters'] ?></div>
              <div><span class="qf-badge qf-badge-muted">Passives</span> <?= $s['passives'] ?></div>
              <div><span class="qf-badge qf-badge-err">Detractors</span> <?= $s['detractors'] ?></div>
            </div>
          </div>

        <?php elseif ($s['kind']==='text'): ?>
          <?php if (!empty($s['words'])): ?>
            <div class="flex flex-wrap gap-2 mb-3">
              <?php foreach ($s['words'] as $w=>$c): $size=0.8 + ($maxWord?($c/$maxWord):0)*1.1; ?>
                <span class="text-slate-700" style="font-size:<?= round($size,2) ?>rem;opacity:<?= round(0.5+($maxWord?$c/$maxWord:0)*0.5,2) ?>"><?= e($w) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <details class="text-sm">
            <summary class="cursor-pointer text-slate-500">View <?= count($s['texts']) ?> individual response(s)</summary>
            <ul class="mt-2 space-y-1 max-h-64 overflow-auto list-disc pl-5">
              <?php foreach ($s['texts'] as $t): ?><li class="break-words"><?= e($t) ?></li><?php endforeach; ?>
            </ul>
          </details>

        <?php else: ?>
          <p class="text-sm text-slate-500"><?= (int)$s['total'] ?> response(s) collected.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
