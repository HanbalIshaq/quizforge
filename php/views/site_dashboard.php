<?php /** Site-admin control room. Expects $stats + recent* feeds. */ ?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
  <div>
    <div class="flex items-center gap-2">
      <span class="qf-badge qf-badge-brand">Site admin</span>
      <span class="flex items-center gap-1 text-xs text-slate-500"><span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> live</span>
    </div>
    <h1 class="text-2xl sm:text-3xl font-bold mt-1">Platform overview</h1>
    <p class="text-sm text-slate-500">Whole-site status. Your personal workspace is under <a href="<?= e(url('/admin')) ?>" class="text-brand-700 hover:underline">My dashboard</a>.</p>
  </div>
  <div class="flex flex-wrap gap-2">
    <a href="<?= e(url('/admin/site/users')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">Users</a>
    <a href="<?= e(url('/admin/site/settings')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">Settings &amp; ads</a>
  </div>
</div>

<!-- Live stat cards -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-8">
  <?php
  $cards = [
    ['Live now (taking)', 'live_taking', 'emerald', 'people mid-quiz (last 15 min)'],
    ['Responses (24h)', 'attempts_24h', 'brand', 'submitted in last day'],
    ['Total users', 'users', 'purple', $stats['users_24h'].' new today'],
    ['Total responses', 'attempts', 'amber', 'all time'],
  ];
  $bg = ['emerald'=>'text-emerald-600','brand'=>'text-brand-700','purple'=>'text-purple-700','amber'=>'text-amber-600'];
  foreach ($cards as [$label,$key,$color,$sub]): ?>
    <div class="qf-card qf-card-pad">
      <p class="text-xs text-slate-500 uppercase tracking-wide"><?= e($label) ?></p>
      <p class="text-3xl font-bold <?= $bg[$color] ?> mt-1" data-stat="<?= e($key) ?>"><?= (int)$stats[$key] ?></p>
      <p class="text-xs text-slate-400 mt-0.5"><?= e($sub) ?></p>
    </div>
  <?php endforeach; ?>
</section>

<section class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-8 text-center">
  <?php foreach ([['Quizzes','quizzes'],['Questions','questions'],['Certificates','certs'],['New users (24h)','users_24h']] as [$l,$k]): ?>
    <div class="qf-card qf-card-pad"><p class="text-xl font-bold text-slate-800"><?= (int)$stats[$k] ?></p><p class="text-xs text-slate-500"><?= e($l) ?></p></div>
  <?php endforeach; ?>
</section>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <!-- Recent attempts (tests conducted) -->
  <section class="qf-card lg:col-span-2">
    <h2 class="px-4 py-3 font-semibold border-b border-slate-100">Recent tests conducted</h2>
    <div class="qf-table-wrap">
      <table class="w-full text-sm qf-sortable">
        <thead class="bg-slate-50 text-left text-slate-600"><tr>
          <th class="px-4 py-2">Participant</th><th class="px-4 py-2">Quiz</th><th class="px-4 py-2">Type</th>
          <th class="px-4 py-2">Owner</th><th class="px-4 py-2">Score</th><th class="px-4 py-2">When</th>
        </tr></thead>
        <tbody>
          <?php foreach ($recentAttempts as $a): ?>
            <tr class="border-t border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-2 font-medium"><?= e($a['student_name'] ?: 'Anonymous') ?></td>
              <td class="px-4 py-2"><?= e($a['quiz_title']) ?></td>
              <td class="px-4 py-2"><span class="qf-badge qf-badge-muted"><?= e(ucfirst($a['kind'])) ?></span></td>
              <td class="px-4 py-2 text-slate-500"><?= e($a['owner']) ?></td>
              <td class="px-4 py-2" data-sort="<?= (float)$a['percentage'] ?>"><?= $a['kind']==='exam'?round((float)$a['percentage']).'%':'—' ?></td>
              <td class="px-4 py-2 text-slate-500 text-xs" data-sort="<?= (int)$a['submitted_at'] ?>"><?= e(fmt_ts($a['submitted_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$recentAttempts): ?><tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">No responses yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Recent users -->
  <section class="qf-card">
    <h2 class="px-4 py-3 font-semibold border-b border-slate-100">New users</h2>
    <div class="qf-table-wrap">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-4 py-2">Email</th><th class="px-4 py-2">Joined</th></tr></thead>
        <tbody>
          <?php foreach ($recentUsers as $u): ?>
            <tr class="border-t border-slate-100">
              <td class="px-4 py-2"><?= e($u['email']) ?> <?php if($u['is_super_admin']): ?><span class="qf-badge qf-badge-brand">admin</span><?php endif; ?><?php if($u['is_suspended']): ?><span class="qf-badge qf-badge-err">suspended</span><?php endif; ?></td>
              <td class="px-4 py-2 text-slate-500 text-xs"><?= e(fmt_ts($u['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Recent quizzes -->
  <section class="qf-card">
    <h2 class="px-4 py-3 font-semibold border-b border-slate-100">New quizzes</h2>
    <div class="qf-table-wrap">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-4 py-2">Title</th><th class="px-4 py-2">Owner</th></tr></thead>
        <tbody>
          <?php foreach ($recentQuizzes as $q): ?>
            <tr class="border-t border-slate-100"><td class="px-4 py-2"><?= e($q['title']) ?> <span class="qf-badge qf-badge-muted"><?= e($q['kind']) ?></span></td><td class="px-4 py-2 text-slate-500"><?= e($q['owner']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<script>
// Live counter refresh every 20s
(function(){
  function refresh(){
    fetch('<?= e(url('/admin/site/live.json')) ?>').then(function(r){return r.json();}).then(function(d){
      document.querySelectorAll('[data-stat]').forEach(function(el){
        var k=el.getAttribute('data-stat'); if(d[k]!==undefined) el.textContent=d[k];
      });
    }).catch(function(){});
  }
  setInterval(refresh, 20000);
})();
</script>
