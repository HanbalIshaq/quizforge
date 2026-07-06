<?php
/** One question card in the editor. Expects $q (decoded), $qi (1-based index), $quiz. */
$typeLabels = [];
foreach (question_types() as [$v,$l,$g]) { $typeLabels[$v] = $l; }
$tLabel = $typeLabels[$q['type']] ?? $q['type'];
$correct = array_map('intval', (array)($q['correct_answers'] ?? []));
?>
<div class="qf-card qf-card-pad" data-qid="<?= (int)$q['id'] ?>">
  <div class="flex flex-col sm:flex-row sm:items-start gap-3">
    <div class="flex-1 min-w-0">
      <div class="text-xs text-slate-500 mb-1">
        Q<?= (int)$qi ?> &middot; <?= e($tLabel) ?> &middot; <?= (int)$q['points'] ?> pt<?= $q['points']!=1?'s':'' ?>
        <?php if (!empty($q['time_limit_seconds'])): ?> &middot; <span class="text-amber-600">⏱ <?= (int)$q['time_limit_seconds'] ?>s</span><?php endif; ?>
        <?php if (empty($q['is_required'])): ?> &middot; <span class="text-slate-400">optional</span><?php endif; ?>
      </div>
      <div class="font-medium break-words"><?= e($q['text']) ?></div>
      <?php if (!empty($q['options']) && is_array($q['options'])): ?>
        <ul class="mt-2 text-sm text-slate-700 space-y-0.5">
          <?php foreach ($q['options'] as $oi => $opt): ?>
            <li class="flex items-start gap-2">
              <span class="inline-block w-5 text-slate-500 shrink-0"><?= chr(65 + $oi) ?>.</span>
              <span class="break-words min-w-0"><?= e($opt) ?></span>
              <?php if (in_array($oi, $correct, true)): ?><span class="text-emerald-600 text-xs shrink-0">&check; correct</span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php elseif (!empty($correct) && in_array($q['type'], ['short_answer','fill_blank'], true)): ?>
        <div class="mt-2 text-sm text-emerald-700">Accepted: <?= e(implode(' / ', (array)$q['correct_answers'])) ?></div>
      <?php endif; ?>
      <?php if (!empty($q['explanation'])): ?>
        <div class="mt-2 text-xs text-slate-500 italic break-words"><?= e($q['explanation']) ?></div>
      <?php endif; ?>
    </div>
    <div class="flex sm:flex-col gap-2 sm:gap-1 shrink-0 justify-end">
      <button type="button" class="qf-btn qf-btn-secondary qf-btn-sm q-edit" data-qid="<?= (int)$q['id'] ?>" aria-label="Edit question <?= (int)$qi ?>">Edit</button>
      <form method="post" action="<?= e(url('/admin/quizzes/'.$quiz['id'].'/questions/'.$q['id'].'/delete')) ?>"
            onsubmit="return confirm('Delete this <?= $quiz['kind']==='form'?'field':'question' ?>?')">
        <?= csrf_field() ?>
        <button class="qf-btn qf-btn-danger qf-btn-sm" aria-label="Delete question <?= (int)$qi ?>">Delete</button>
      </form>
    </div>
  </div>
</div>
