<?php
/** Admin per-attempt detail + manual grading. Expects $quiz, $attempt, $questions, $answers. */
function opt_label_php(array $options, $idx) {
    $n = to_int($idx, null);
    if ($n === null || $n < 0 || $n >= count($options)) return $idx;
    return $options[$n];
}
?>
<a href="<?= e(url('/admin/quizzes/'.$quiz['id'].'/results')) ?>" class="text-sm text-slate-500 hover:text-brand-700">&larr; All results</a>
<h1 class="text-2xl font-bold mt-1"><?= e($attempt['student_name'] ?: 'Anonymous') ?></h1>
<p class="text-sm text-slate-500 mb-6">
  <?php if ($quiz['kind']==='exam'): ?>
    Score: <?= rtrim(rtrim(number_format((float)$attempt['score'],1),'0'),'.') ?> / <?= number_format((float)$attempt['max_score']) ?>
    (<?= number_format((float)$attempt['percentage']) ?>%) &middot;
  <?php endif; ?>
  submitted <?= e(fmt_ts($attempt['submitted_at'])) ?>
  <?php if ($attempt['student_email']): ?> &middot; <?= e($attempt['student_email']) ?><?php endif; ?>
</p>

<form method="post" action="<?= e(url('/admin/quizzes/'.$quiz['id'].'/attempts/'.$attempt['id'])) ?>" class="space-y-3">
  <?= csrf_field() ?>
  <?php $n=0; foreach ($questions as $q): if($q['type']==='section_break') continue; $n++; $a=$answers[$q['id']]??null; ?>
    <div class="qf-card qf-card-pad">
      <div class="text-xs text-slate-500 mb-1">Q<?= $n ?> &middot; <?= e(str_replace('_',' ',$q['type'])) ?> &middot; max <?= (int)$q['points'] ?> pt</div>
      <div class="font-medium mb-2"><?= e($q['text']) ?></div>
      <div class="text-sm">
        <span class="text-slate-500">Answer:</span>
        <?php if ($a && $a['value'] !== null):
          $v = $a['value'];
          if (!empty($q['options']) && is_array($q['options'])) {
            if (is_array($v)) { echo e(implode(', ', array_map(fn($i)=>opt_label_php($q['options'],$i), $v))); }
            else { echo e(opt_label_php($q['options'], $v)); }
          } else {
            echo e(is_array($v)?implode(', ',$v):(string)$v);
          }
        else: ?><em class="text-slate-400">no answer</em><?php endif; ?>
      </div>
      <?php if (!empty($q['correct_answers']) && !empty($q['options'])): ?>
        <div class="text-xs text-emerald-700 mt-1">Correct: <?= e(implode(', ', array_map(fn($i)=>opt_label_php($q['options'],$i), array_map('intval',(array)$q['correct_answers'])))) ?></div>
      <?php elseif (!empty($q['correct_answers']) && in_array($q['type'],['short_answer','fill_blank'],true)): ?>
        <div class="text-xs text-emerald-700 mt-1">Accepted: <?= e(implode(' / ',(array)$q['correct_answers'])) ?></div>
      <?php endif; ?>
      <?php if ($a): ?>
        <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3">
          <label class="text-xs text-slate-600">Points
            <input type="number" name="pts_<?= (int)$a['id'] ?>" value="<?= rtrim(rtrim(number_format((float)$a['points_earned'],1),'0'),'.') ?>" step="0.5" min="0" max="<?= (int)$q['points'] ?>"
                   class="ml-1 w-20 px-2 py-1 border rounded text-sm" /></label>
          <input type="text" name="fb_<?= (int)$a['id'] ?>" value="<?= e($a['feedback']) ?>" placeholder="Feedback (optional)" class="flex-1 px-2 py-1 border rounded text-sm" />
          <?php if ($a['is_correct']==1): ?><span class="text-emerald-600 text-xs">✓ correct</span>
          <?php elseif ($a['is_correct']!==null): ?><span class="text-red-600 text-xs">✗ incorrect</span>
          <?php else: ?><span class="text-amber-600 text-xs">manual</span><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <button class="qf-btn qf-btn-primary">Save grading</button>
</form>
