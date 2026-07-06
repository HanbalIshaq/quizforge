<?php
/** Base layout. Expects $content (pre-rendered HTML) + optional $title. */
$u = current_user();
$flashes = take_flashes();
$feat = features_all();
$pageTitle = $title ?? (app_name() . ' — ' . app_tagline());
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e(app_name()) ?> — create quizzes, exams, polls, surveys and forms. Auto-graded, anti-cheating, certificates, AI quiz generation." />
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>" />
  <meta name="theme-color" content="#f8fafc" />
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="<?= e(url('/assets/css/custom.css')) ?>" />
  <script>
    tailwind.config = {
      theme: { extend: { colors: {
        brand: { 50:'#eef2ff',100:'#e0e7ff',500:'#6366f1',600:'#4f46e5',700:'#4338ca',900:'#312e81' }
      } } }
    };
  </script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col">
  <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-3 focus:py-2 focus:bg-brand-700 focus:text-white focus:rounded">Skip to main content</a>

  <header class="bg-white border-b border-slate-200 sticky top-0 z-30">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3 sm:gap-6">
      <a href="<?= e(url('/')) ?>" class="font-bold text-lg text-brand-700 flex items-center gap-2 shrink-0">
        <span class="inline-block w-7 h-7 rounded bg-brand-600 text-white grid place-items-center text-sm" aria-hidden="true"><?= e(mb_substr(app_name(), 0, 1)) ?></span>
        <span><?= e(app_name()) ?></span>
      </a>

      <nav class="hidden md:flex items-center gap-5 text-sm text-slate-700" aria-label="Primary">
        <a href="<?= e(url('/')) ?>" class="hover:text-brand-700">Home</a>
        <a href="<?= e(url('/join')) ?>" class="hover:text-brand-700">Take a quiz</a>
      </nav>

      <div class="hidden md:flex items-center gap-3 text-sm">
        <?php if ($u): ?>
          <a href="<?= e(url('/admin')) ?>" class="hover:text-brand-700">Dashboard</a>
          <span class="text-slate-600 max-w-[10rem] truncate" title="<?= e($u['email']) ?>"><?= e($u['name'] ?: $u['email']) ?></span>
          <a href="<?= e(url('/logout')) ?>" class="text-slate-500 hover:text-red-600">Sign out</a>
        <?php else: ?>
          <a href="<?= e(url('/login')) ?>" class="hover:text-brand-700">Sign in</a>
          <?php if ($feat['feature_registration']): ?>
            <a href="<?= e(url('/register')) ?>" class="px-3 py-1.5 bg-brand-600 text-white rounded hover:bg-brand-700 inline-flex items-center min-h-[36px]">Get started — free</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <button type="button" id="qf-nav-toggle" class="md:hidden -mr-2 inline-flex items-center justify-center w-11 h-11 rounded-lg text-slate-700 hover:bg-slate-100" aria-controls="qf-mobile-nav" aria-expanded="false" aria-label="Open menu">
        <svg id="qf-nav-icon-open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        <svg id="qf-nav-icon-close" class="w-6 h-6 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <nav id="qf-mobile-nav" class="md:hidden hidden border-t border-slate-200 bg-white" aria-label="Mobile">
      <div class="max-w-6xl mx-auto px-4 py-2 flex flex-col">
        <a href="<?= e(url('/')) ?>" class="min-h-[44px] flex items-center text-slate-700 hover:bg-slate-50 px-2 rounded">Home</a>
        <a href="<?= e(url('/join')) ?>" class="min-h-[44px] flex items-center text-slate-700 hover:bg-slate-50 px-2 rounded">Take a quiz</a>
        <div class="my-1 border-t border-slate-200"></div>
        <?php if ($u): ?>
          <div class="px-2 py-2 text-xs text-slate-500 truncate">Signed in as <span class="text-slate-700 font-medium"><?= e($u['name'] ?: $u['email']) ?></span></div>
          <a href="<?= e(url('/admin')) ?>" class="min-h-[44px] flex items-center text-slate-700 hover:bg-slate-50 px-2 rounded">Dashboard</a>
          <a href="<?= e(url('/logout')) ?>" class="min-h-[44px] flex items-center text-red-600 hover:bg-red-50 px-2 rounded">Sign out</a>
        <?php else: ?>
          <a href="<?= e(url('/login')) ?>" class="min-h-[44px] flex items-center text-slate-700 hover:bg-slate-50 px-2 rounded">Sign in</a>
          <?php if ($feat['feature_registration']): ?>
            <a href="<?= e(url('/register')) ?>" class="min-h-[44px] flex items-center justify-center px-3 my-2 bg-brand-600 text-white rounded hover:bg-brand-700">Get started — free</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </nav>
  </header>

  <main id="main-content" class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 py-4 sm:py-6">
    <?php if ($flashes): ?>
      <div class="mb-4 space-y-2" role="status" aria-live="polite">
        <?php foreach ($flashes as $f): ?>
          <div class="px-4 py-2 rounded border text-sm
            <?php if ($f['cat']==='error'): ?>bg-red-50 border-red-200 text-red-800
            <?php elseif ($f['cat']==='success'): ?>bg-emerald-50 border-emerald-200 text-emerald-800
            <?php else: ?>bg-slate-100 border-slate-200<?php endif; ?>">
            <?= e($f['msg']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?= $content ?? '' ?>
  </main>

  <footer class="bg-slate-900 text-slate-300 mt-12">
    <div class="max-w-6xl mx-auto px-4 py-6 text-xs text-slate-500 flex flex-wrap justify-between gap-2">
      <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?>. Runs on any PHP + MySQL host.</span>
      <span>Online quiz maker · exam software · live polls · surveys · forms</span>
    </div>
  </footer>

  <script>
  (function(){
    var btn=document.getElementById('qf-nav-toggle'),drawer=document.getElementById('qf-mobile-nav'),
        io=document.getElementById('qf-nav-icon-open'),ic=document.getElementById('qf-nav-icon-close');
    if(!btn||!drawer)return;
    function set(o){drawer.classList.toggle('hidden',!o);io.classList.toggle('hidden',o);ic.classList.toggle('hidden',!o);btn.setAttribute('aria-expanded',o?'true':'false');}
    btn.addEventListener('click',function(){set(drawer.classList.contains('hidden'));});
    drawer.addEventListener('click',function(ev){if(ev.target.tagName==='A')set(false);});
    document.addEventListener('keydown',function(ev){if(ev.key==='Escape'&&!drawer.classList.contains('hidden'))set(false);});
  })();
  // CSRF auto-inject into POST forms + fetch
  (function(){
    var t=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
    if(!t)return;
    document.addEventListener('submit',function(e){
      var f=e.target; if(!f||!f.method)return;
      if(f.method.toUpperCase()!=='POST')return;
      if(f.querySelector('input[name="_csrf"]'))return;
      var i=document.createElement('input');i.type='hidden';i.name='_csrf';i.value=t;f.appendChild(i);
    },true);
    if(window.fetch){var of=window.fetch;window.fetch=function(inp,ini){ini=ini||{};var m=(ini.method||'GET').toUpperCase();if(m==='POST'||m==='PUT'||m==='PATCH'||m==='DELETE'){var h=new Headers(ini.headers||{});if(!h.has('X-CSRF-Token'))h.set('X-CSRF-Token',t);ini.headers=h;}return of.call(this,inp,ini);};}
  })();
  // Copy-to-clipboard helper
  (function(){
    document.addEventListener('click',function(e){
      var b=e.target.closest('[data-copy]'); if(!b)return;
      var txt=b.getAttribute('data-copy'); if(!txt)return;
      var done=function(){var o=b.textContent;b.textContent='Copied!';setTimeout(function(){b.textContent=o;},1500);};
      if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(txt).then(done).catch(function(){});}
    });
  })();
  </script>
</body>
</html>
