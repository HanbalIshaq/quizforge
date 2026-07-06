<?php
/** Student result. Expects $quiz, $attempt, $questions, $answers. */
$isExam = $quiz['kind'] === 'exam';
$pct = (float)($attempt['percentage'] ?? 0);
$passed = $quiz['pass_mark'] ? ($pct >= (float)$quiz['pass_mark']) : null;
?>
<div class="qf-card qf-card-pad text-center mb-6" style="padding:2rem">
  <h1 class="text-2xl font-bold mb-1"><?= e($quiz['title']) ?></h1>
  <p class="text-sm text-slate-500">Thanks, <?= e($attempt['student_name'] ?: 'Anonymous') ?>!</p>

  <?php if (!$isExam): ?>
    <p class="mt-4 text-emerald-700"><?= $quiz['kind']==='survey' ? 'Your anonymous response was recorded.' : 'Your response was recorded.' ?></p>
  <?php elseif ($attempt['needs_grading']): ?>
    <p class="mt-4 text-amber-700">Your answers are pending manual review.</p>
  <?php else: ?>
    <div class="text-5xl font-bold mt-4 <?= $passed===true?'text-emerald-600':($passed===false?'text-red-600':'text-brand-700') ?>"><?= number_format($pct) ?>%</div>
    <p class="text-slate-600 mt-1"><?= rtrim(rtrim(number_format((float)$attempt['score'],1),'0'),'.') ?> / <?= number_format((float)$attempt['max_score']) ?> points</p>
    <?php if ($quiz['pass_mark']): ?>
      <p class="mt-2 inline-block px-3 py-1 rounded <?= $passed?'bg-emerald-100 text-emerald-700':'bg-red-100 text-red-700' ?>"><?= $passed?'PASS':'FAIL' ?> (pass mark <?= (int)$quiz['pass_mark'] ?>%)</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($isExam && $quiz['show_correct_answers'] && !$attempt['needs_grading']): ?>
  <h2 class="font-semibold mb-3">Review</h2>
  <div class="space-y-3">
    <?php $n=0; foreach ($questions as $q): if($q['type']==='section_break') continue; $n++; $a=$answers[$q['id']]??null;
      $correct = array_map('intval',(array)$q['correct_answers']); ?>
      <div class="qf-card qf-card-pad">
        <p class="text-xs text-slate-500 mb-1">Q<?= $n ?>
          <?php if ($a && $a['is_correct']==1): ?><span class="text-emerald-600">✓ correct</span>
          <?php elseif ($a && $a['is_correct']==='0' || ($a && $a['is_correct']==0 && $a['is_correct']!==null)): ?><span class="text-red-600">✗ incorrect</span><?php endif; ?>
        </p>
        <p class="font-medium mb-2"><?= e($q['text']) ?></p>
        <?php if (!empty($q['options']) && is_array($q['options'])): ?>
          <ul class="text-sm space-y-1">
            <?php foreach ($q['options'] as $oi=>$opt):
              $isC = in_array($oi,$correct,true);
              $picked = $a && ($a['value']===$oi || (is_array($a['value']) && in_array($oi,array_map('intval',$a['value']),true))); ?>
              <li class="flex items-center gap-2 <?= $isC?'text-emerald-700':($picked?'text-red-700':'') ?>">
                <span class="w-5"><?= chr(65+$oi) ?>.</span><span><?= e($opt) ?></span>
                <?php if($isC): ?><span>✓</span><?php elseif($picked): ?><span>✗</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($a): ?>
          <p class="text-sm">Your answer: <span class="font-mono"><?= e(is_array($a['value'])?implode(', ',$a['value']):(string)$a['value']) ?></span></p>
          <?php if (!empty($q['correct_answers'])): ?><p class="text-sm text-emerald-700">Accepted: <?= e(implode(' / ',(array)$q['correct_answers'])) ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($q['explanation'])): ?><p class="text-xs text-slate-500 italic mt-2"><?= e($q['explanation']) ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="mt-6 text-center">
  <a href="<?= e(url('/')) ?>" class="text-sm text-brand-700 hover:underline">Back to home</a>
</div>
