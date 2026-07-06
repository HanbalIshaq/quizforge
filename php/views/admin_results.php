<?php /** Admin results list. Expects $quiz, $attempts, $nq. */ ?>
<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-5">
  <div class="min-w-0">
    <a href="<?= e(url('/admin/quizzes/'.$quiz['id'])) ?>" class="text-sm text-slate-500 hover:text-brand-700">&larr; Edit quiz</a>
    <h1 class="text-2xl font-bold mt-1 break-words">Results: <?= e($quiz['title']) ?></h1>
    <p class="text-sm text-slate-500"><?= count($attempts) ?> response(s)</p>
  </div>
  <div class="flex flex-wrap gap-2 shrink-0">
    <a href="<?= e(url('/admin/quizzes/'.$quiz['id'].'/export.csv')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">Export CSV</a>
  </div>
</div>

<?php if ($attempts): ?>
  <div class="qf-card">
    <div class="qf-table-wrap">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600">
          <tr>
            <th class="px-4 py-2.5">Name</th>
            <th class="px-4 py-2.5">Email</th>
            <?php if ($quiz['kind']==='exam'): ?><th class="px-4 py-2.5">Score</th><th class="px-4 py-2.5">%</th><?php endif; ?>
            <th class="px-4 py-2.5">Submitted</th>
            <th class="px-4 py-2.5"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $a): ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-2.5 font-medium"><?= e($a['student_name'] ?: 'Anonymous') ?></td>
              <td class="px-4 py-2.5 text-slate-500"><?= e($a['student_email']) ?></td>
              <?php if ($quiz['kind']==='exam'): ?>
                <td class="px-4 py-2.5"><?= rtrim(rtrim(number_format((float)$a['score'],1),'0'),'.') ?> / <?= number_format((float)$a['max_score']) ?></td>
                <td class="px-4 py-2.5">
                  <?= number_format((float)$a['percentage']) ?>%
                  <?php if ($a['needs_grading']): ?><span class="qf-badge qf-badge-warn ml-1">Needs grading</span>
                  <?php elseif ($quiz['pass_mark']): ?>
                    <?php $pass=(float)$a['percentage']>=(float)$quiz['pass_mark']; ?>
                    <span class="qf-badge <?= $pass?'qf-badge-ok':'qf-badge-err' ?> ml-1"><?= $pass?'Pass':'Fail' ?></span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td class="px-4 py-2.5 text-slate-500 text-xs"><?= e(fmt_ts($a['submitted_at'])) ?></td>
              <td class="px-4 py-2.5 text-right">
                <a href="<?= e(url('/admin/quizzes/'.$quiz['id'].'/attempts/'.$a['id'])) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div class="qf-card qf-card-pad text-center py-12 text-slate-500">
    <p class="text-4xl mb-3">📭</p>
    <p class="text-lg font-semibold text-slate-900 mb-1">No responses yet</p>
    <p class="text-sm">Share the code <span class="font-mono bg-slate-100 px-2 py-0.5 rounded"><?= e($quiz['share_code']) ?></span> to start collecting responses.</p>
  </div>
<?php endif; ?>
