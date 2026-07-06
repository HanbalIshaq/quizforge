<?php /** Error page. Expects $code, $message, optional $err_id, $detail. */ ?>
<div class="max-w-2xl mx-auto py-12">
  <div class="bg-white border <?= ($code ?? 500) >= 500 ? 'border-red-200' : 'border-slate-200' ?> rounded-lg p-8 shadow-sm">
    <div class="flex items-start gap-4">
      <div class="text-4xl" aria-hidden="true"><?= ($code ?? 500) >= 500 ? '⚠️' : '🔍' ?></div>
      <div class="flex-1">
        <h1 class="text-2xl font-bold text-slate-900"><?= e($message ?? 'Error') ?></h1>
        <?php if (!empty($err_id)): ?>
          <p class="mt-2 text-sm text-slate-500">Error ID: <span class="font-mono">err_<?= e($err_id) ?></span> — logged on the server.</p>
        <?php endif; ?>
        <div class="mt-6 flex flex-wrap gap-2">
          <a href="javascript:history.back()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded text-sm">&larr; Go back</a>
          <a href="<?= e(url('/')) ?>" class="px-4 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded text-sm">Home</a>
        </div>
      </div>
    </div>
    <?php if (!empty($detail)): ?>
      <details class="mt-6 border-t border-slate-200 pt-4">
        <summary class="cursor-pointer text-sm font-semibold text-red-700">Traceback (super-admin only)</summary>
        <pre class="mt-3 text-xs bg-slate-900 text-red-200 p-4 rounded overflow-x-auto whitespace-pre-wrap break-all"><?= e($detail) ?></pre>
      </details>
    <?php endif; ?>
  </div>
</div>
