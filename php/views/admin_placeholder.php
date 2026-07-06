<?php /** Temporary dashboard — replaced by the real one in Step 2. */ ?>
<div class="mb-6">
  <h1 class="text-3xl font-bold">Welcome back, <?= e($u['name'] ?: explode('@', $u['email'])[0]) ?></h1>
  <p class="text-sm text-slate-500 mt-1">You're signed in. The full dashboard arrives in the next build step.</p>
</div>
<div class="bg-white border border-slate-200 rounded-xl p-6">
  <p class="text-sm text-slate-600">Foundation is live ✓ — auth, sessions, database, and layout all working.</p>
  <ul class="mt-3 text-sm text-slate-600 list-disc pl-5 space-y-1">
    <li>Step 2 (next): quiz creation + question editor + dashboard cards</li>
    <li>Step 3: take-quiz flow + grading + results</li>
    <li>Step 4: polls / surveys / forms</li>
    <li>Step 5: exports, certificates, AI generation, email</li>
    <li>Step 6: multi-tenant orgs, plans, live polling</li>
  </ul>
  <div class="mt-4">
    <a href="<?= e(url('/logout')) ?>" class="text-sm text-brand-700 hover:underline">Sign out</a>
  </div>
</div>
