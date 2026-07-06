<?php /** Live host control room. Expects $session, $quiz, $joinUrl, $total. */ ?>
<div class="max-w-4xl mx-auto px-4 py-6" id="live-host"
     data-poll="<?= e(url('/admin/live/' . $session['id'] . '/host.json')) ?>"
     data-next="<?= e(url('/admin/live/' . $session['id'] . '/next')) ?>"
     data-end="<?= e(url('/admin/live/' . $session['id'] . '/end')) ?>"
     data-total="<?= (int)$total ?>">

  <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
    <div>
      <a href="<?= e(url('/admin/quizzes/' . $quiz['id'] . '/edit')) ?>" class="text-sm text-slate-400 hover:text-brand-700">← <?= e($quiz['title']) ?></a>
      <h1 class="text-2xl font-bold">Live session</h1>
    </div>
    <div class="flex gap-2">
      <button id="btn-next" class="qf-btn qf-btn-primary">Start</button>
      <button id="btn-end" class="qf-btn qf-btn-ghost text-red-600">End</button>
    </div>
  </div>

  <!-- Join banner -->
  <div class="qf-card qf-card-pad mb-5 flex flex-wrap items-center justify-between gap-4" style="background:linear-gradient(90deg,#eef2ff,#fdf4ff)">
    <div>
      <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Join at</p>
      <p class="text-lg font-semibold text-brand-700"><?= e(preg_replace('#^https?://#', '', $joinUrl)) ?></p>
    </div>
    <div class="text-right">
      <p class="text-xs uppercase tracking-wide text-slate-500 mb-1">Game PIN</p>
      <p class="text-4xl font-mono font-bold tracking-widest"><?= e($session['join_code']) ?></p>
    </div>
    <div class="text-center">
      <p class="text-3xl font-bold text-brand-700" id="stat-players">0</p>
      <p class="text-xs text-slate-500">players</p>
    </div>
  </div>

  <div class="grid gap-5 md:grid-cols-2">
    <!-- Left: current question / roster -->
    <div class="qf-card qf-card-pad">
      <div id="host-roster">
        <h2 class="font-semibold mb-3">Players <span class="text-slate-400 font-normal text-sm">(<span id="roster-n">0</span>)</span></h2>
        <div id="roster-list" class="flex flex-wrap gap-2 text-sm"></div>
        <p class="text-slate-400 text-sm mt-3">Press <strong>Start</strong> when everyone's in.</p>
      </div>
      <div id="host-question" class="hidden">
        <p class="text-xs text-slate-400 mb-1">Question <span id="hq-n">1</span> of <span id="hq-total"><?= (int)$total ?></span> · <span id="hq-answered">0</span> answered</p>
        <h2 id="hq-prompt" class="font-bold text-lg mb-4"></h2>
        <div id="hq-options" class="space-y-2"></div>
      </div>
      <div id="host-ended" class="hidden text-center">
        <div class="text-4xl mb-2">🏁</div>
        <p class="font-semibold">Session ended</p>
      </div>
    </div>

    <!-- Right: leaderboard -->
    <div class="qf-card qf-card-pad">
      <h2 class="font-semibold mb-3">Leaderboard</h2>
      <ol id="host-board" class="space-y-1 text-sm"></ol>
      <p id="host-board-empty" class="text-slate-400 text-sm">No scores yet.</p>
    </div>
  </div>
</div>

<script>
(function(){
  var root=document.getElementById('live-host');
  var pollUrl=root.dataset.poll, nextUrl=root.dataset.next, endUrl=root.dataset.end;
  var total=parseInt(root.dataset.total,10)||0;
  var csrf=document.querySelector('meta[name="csrf-token"]').content;
  var btnNext=document.getElementById('btn-next'), btnEnd=document.getElementById('btn-end');
  var elRoster=document.getElementById('host-roster'), elQ=document.getElementById('host-question'),
      elEnded=document.getElementById('host-ended');
  var status='waiting';

  function post(url){ return fetch(url,{method:'POST',headers:{'X-CSRF-Token':csrf}}).then(function(r){return r.json();}); }
  function escapeHtml(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

  btnNext.addEventListener('click',function(){ btnNext.disabled=true; post(nextUrl).then(function(){ btnNext.disabled=false; poll(); }); });
  btnEnd.addEventListener('click',function(){ if(!confirm('End this live session for everyone?'))return; post(endUrl).then(function(){ poll(); }); });

  function renderQuestion(q,answered,dist){
    document.getElementById('hq-n').textContent=q.n;
    document.getElementById('hq-answered').textContent=answered;
    document.getElementById('hq-prompt').textContent=q.prompt;
    var box=document.getElementById('hq-options'); box.innerHTML='';
    var totalAns=(dist||[]).reduce(function(a,b){return a+b;},0)||1;
    (q.options||[]).forEach(function(txt,i){
      var c=(dist&&dist[i])||0, pct=Math.round(c/totalAns*100);
      var correct=(q.correct||[]).indexOf(i)>=0;
      var row=document.createElement('div');
      row.innerHTML='<div class="flex justify-between text-sm mb-1"><span>'+(correct?'✅ ':'')+escapeHtml(txt)+'</span><span class="text-slate-400">'+c+'</span></div>'+
        '<div style="height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden"><div style="height:100%;width:'+pct+'%;background:'+(correct?'#16a34a':'#6366f1')+'"></div></div>';
      box.appendChild(row);
    });
  }

  function renderBoard(list){
    var ol=document.getElementById('host-board'), empty=document.getElementById('host-board-empty');
    ol.innerHTML='';
    if(!list||!list.length){ empty.classList.remove('hidden'); return; }
    empty.classList.add('hidden');
    list.forEach(function(p,i){
      var li=document.createElement('li');
      li.className='flex justify-between';
      var medal=i===0?'🥇':i===1?'🥈':i===2?'🥉':(i+1)+'.';
      li.innerHTML='<span>'+medal+' '+escapeHtml(p.name)+'</span><span class="font-semibold">'+Math.round(p.score)+'</span>';
      ol.appendChild(li);
    });
  }

  function show(el){ [elRoster,elQ,elEnded].forEach(function(e){e.classList.add('hidden');}); el.classList.remove('hidden'); }

  function poll(){
    fetch(pollUrl,{cache:'no-store'}).then(function(r){return r.json();}).then(function(s){
      status=s.status;
      document.getElementById('stat-players').textContent=s.players||0;
      document.getElementById('roster-n').textContent=s.players||0;
      var rl=document.getElementById('roster-list'); rl.innerHTML='';
      (s.roster||[]).forEach(function(n){ var b=document.createElement('span'); b.className='qf-badge'; b.textContent=n; rl.appendChild(b); });
      renderBoard(s.leaderboard);

      if(s.status==='waiting'){ show(elRoster); btnNext.textContent='Start'; }
      else if(s.status==='running' && s.question){ show(elQ); btnNext.textContent=(s.index+1>=total?'Finish':'Next question'); renderQuestion(s.question,s.answered||0,s.distribution); }
      else if(s.status==='ended'){ show(elEnded); btnNext.disabled=true; btnEnd.disabled=true; return; }
      setTimeout(poll,2000);
    }).catch(function(){ setTimeout(poll,3000); });
  }
  poll();
})();
</script>
