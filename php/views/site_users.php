<?php /** Site-admin user management. Expects $users. */ $me = current_user(); ?>
<a href="<?= e(url('/admin/site')) ?>" class="text-sm text-slate-500 hover:text-brand-700">&larr; Site admin</a>
<h1 class="text-2xl font-bold mt-1 mb-1">Users</h1>
<p class="text-sm text-slate-500 mb-5"><?= count($users) ?> total. Click a header to sort.</p>

<div class="qf-card">
  <div class="qf-table-wrap">
    <table class="w-full text-sm qf-sortable">
      <thead class="bg-slate-50 text-left text-slate-600">
        <tr>
          <th class="px-4 py-2.5">Email</th>
          <th class="px-4 py-2.5">Name</th>
          <th class="px-4 py-2.5">Quizzes</th>
          <th class="px-4 py-2.5">Role</th>
          <th class="px-4 py-2.5">Status</th>
          <th class="px-4 py-2.5">Joined</th>
          <th class="px-4 py-2.5" data-no-sort></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr class="border-t border-slate-100 hover:bg-slate-50">
            <td class="px-4 py-2.5 font-medium"><?= e($u['email']) ?></td>
            <td class="px-4 py-2.5"><?= e($u['name']) ?></td>
            <td class="px-4 py-2.5"><?= (int)$u['n_quizzes'] ?></td>
            <td class="px-4 py-2.5"><?= $u['is_super_admin'] ? '<span class="qf-badge qf-badge-brand">Super admin</span>' : '<span class="qf-badge qf-badge-muted">User</span>' ?></td>
            <td class="px-4 py-2.5"><?= $u['is_suspended'] ? '<span class="qf-badge qf-badge-err">Suspended</span>' : '<span class="qf-badge qf-badge-ok">Active</span>' ?></td>
            <td class="px-4 py-2.5 text-slate-500 text-xs" data-sort="<?= (int)$u['created_at'] ?>"><?= e(fmt_ts($u['created_at'])) ?></td>
            <td class="px-4 py-2.5 text-right whitespace-nowrap" data-sort="">
              <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                <div class="inline-flex gap-1">
                  <?php if ($u['is_suspended']): ?>
                    <form method="post" action="<?= e(url('/admin/site/users/'.$u['id'].'/unsuspend')) ?>"><?= csrf_field() ?><button class="qf-btn qf-btn-secondary qf-btn-sm">Unsuspend</button></form>
                  <?php else: ?>
                    <form method="post" action="<?= e(url('/admin/site/users/'.$u['id'].'/suspend')) ?>" onsubmit="return confirm('Suspend <?= e($u['email']) ?>?')"><?= csrf_field() ?><button class="qf-btn qf-btn-danger qf-btn-sm">Suspend</button></form>
                  <?php endif; ?>
                  <?php if ($u['is_super_admin']): ?>
                    <form method="post" action="<?= e(url('/admin/site/users/'.$u['id'].'/demote')) ?>"><?= csrf_field() ?><button class="qf-btn qf-btn-secondary qf-btn-sm">Demote</button></form>
                  <?php else: ?>
                    <form method="post" action="<?= e(url('/admin/site/users/'.$u['id'].'/promote')) ?>" onsubmit="return confirm('Make <?= e($u['email']) ?> a super admin?')"><?= csrf_field() ?><button class="qf-btn qf-btn-secondary qf-btn-sm">Make admin</button></form>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="text-xs text-slate-400">(you)</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
