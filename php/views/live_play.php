<?php /** Live player screen. Expects $session, $me. Rendered bare (focused). */ ?>
<div class="max-w-lg mx-auto px-4 py-6" id="live-root"
     data-sid="<?= (int)$session['id'] ?>"
     data-state="<?= e(url('/live/play/' . $session['id'] . '/state.json')) ?>"
     data-answer="<?= e(url('/live/play/' . $session['id'] . '/answer')) ?>">

  <div class="flex items-center justify-between mb-4 text-sm">
    <span class="font-semibold text-slate-700"><?= e($me['name']) ?></span>
    <span class="qf-badge" id="live-score">0 pts</span>
  </div>

  <!-- Lobby -->
  <div id="live-lobby" class="qf-card qf-card-pad text-center" style="padding:2rem">
    <div class="text-4xl mb-3" aria-hidden="true">⏳</div>
    <h1 class="text-xl font-bold mb-1">You're in!</h1>
    <p class="text-slate-500 text-sm">Waiting for the host to start…</p>
    <p class="text-slate-400 text-xs mt-3"><span id="live-players">0</span> players joined</p>
  </div>

  <!-- Question -->
  <div id="live-question" class="hidden">
    <p class="text-xs text-slate-400 mb-1">Question <span id="lq-n">1</span></p>
    <h1 id="lq-prompt" class="text-lg font-bold mb-4"></h1>
    <div id="lq-options" class="grid gap-3"></div>
    <p id="lq-multi-hint" class="text-xs text-slate-400 mt-3 hidden">Pick all that apply, then submit.</p>
    <button id="lq-submit" class="qf-btn qf-btn-primary qf-btn-block mt-4 hidden">Submit</button>
    <div id="lq-waiting" class="hidden text-center mt-6">
      <div class="text-3xl mb-2" id="lq-feedback">✅</div>
      <p class="text-slate-500 text-sm" id="lq-feedback-text">Answer locked in — hang tight.</p>
    </div>
  </div>

  <!-- Ended -->
  <div id="live-ended" class="hidden qf-card qf-card-pad text-center" style="padding:2rem">
    <div class="text-4xl mb-3" aria-hidden="true">🏁</div>
    <h1 class="text-xl font-bold mb-1">That's a wrap!</h1>
    <p class="text-slate-500 text-sm mb-4">Final score: <strong id="live-final">0</strong> pts</p>
    <ol id="live-board" class="text-left text-sm space-y-1"></ol>
  </div>
</div>

<style>
.lq-opt{padding:1rem;border-radius:.75rem;color:#fff;font-weight:600;text-align:left;border:3px solid transparent;cursor:pointer;transition:transform .06s}
.lq-opt:active{transform:scale(.98)}
.lq-opt[aria-pressed="true"]{border-color:#0f172a}
.lq-c0{background:#e11d48}.lq-c1{background:#2563eb}.lq-c2{background:#d97706}.lq-c3{background:#16a34a}
.lq-c4{background:#7c3aed}.lq-c5{background:#0891b2}.lq-c6{background:#db2777}.lq-c7{background:#4b5563}
</style>
<script>
(function(){
  var root=document.getElementById('live-root');
  var stateUrl=root.dataset.state, answerUrl=root.dataset.answer;
  var elLobby=document.getElementById('live-lobby'), elQ=document.getElementById('live-question'),
      elEnded=document.getElementById('live-ended');
  var picked=[], curIndex=-1, multi=false, submitted=false;

  function show(el){ [elLobby,elQ,elEnded].forEach(function(e){e.classList.add('hidden');}); el.classList.remove('hidden'); }

  function renderQuestion(q){
    document.getElementById('lq-n').textContent=q.n;
    document.getElementById('lq-prompt').textContent=q.prompt;
    multi=!!q.multi;
    document.getElementById('lq-multi-hint').classList.toggle('hidden',!multi);
    document.getElementById('lq-submit').classList.toggle('hidden',!multi);
    document.getElementById('lq-waiting').classList.add('hidden');
    var box=document.getElementById('lq-options'); box.innerHTML=''; picked=[];
    (q.options||[]).forEach(function(txt,i){
      var b=document.createElement('button');
      b.type='button'; b.className='lq-opt lq-c'+(i%8); b.textContent=txt;
      b.setAttribute('aria-pressed','false');
      b.addEventListener('click',function(){ onPick(i,b); });
      box.appendChild(b);
    });
    show(elQ);
  }

  function onPick(i,btn){
    if(submitted) return;
    if(multi){
      var at=picked.indexOf(i);
      if(at>=0){picked.splice(at,1);btn.setAttribute('aria-pressed','false');}
      else{picked.push(i);btn.setAttribute('aria-pressed','true');}
    } else {
      picked=[i]; sendAnswer();
    }
  }

  function sendAnswer(){
    if(submitted) return; submitted=true;
    var body=new URLSearchParams();
    body.set('answer',JSON.stringify(multi?picked:(picked[0])));
    fetch(answerUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
      .then(function(r){return r.json();})
      .then(function(d){
        var fb=document.getElementById('lq-feedback'), ft=document.getElementById('lq-feedback-text');
        if(d && d.ok){ fb.textContent=d.correct?'✅':'❌'; ft.textContent=d.correct?('+'+d.award+' points!'):'Locked in.'; }
        else { fb.textContent='✔'; ft.textContent='Locked in.'; }
        document.getElementById('lq-submit').classList.add('hidden');
        document.getElementById('lq-options').classList.add('hidden');
        document.getElementById('lq-waiting').classList.remove('hidden');
      }).catch(function(){});
  }

  document.getElementById('lq-submit').addEventListener('click',function(){ if(picked.length) sendAnswer(); });

  function renderBoard(list,elId){
    var ol=document.getElementById(elId); if(!ol) return; ol.innerHTML='';
    list.forEach(function(p,i){
      var li=document.createElement('li');
      li.className='flex justify-between qf-card qf-card-pad';
      li.style.padding='.5rem .75rem';
      li.innerHTML='<span>'+(i+1)+'. '+escapeHtml(p.name)+'</span><span class="font-semibold">'+p.score+'</span>';
      ol.appendChild(li);
    });
  }
  function escapeHtml(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}

  function poll(){
    fetch(stateUrl,{cache:'no-store'}).then(function(r){return r.json();}).then(function(s){
      if(s.you) document.getElementById('live-score').textContent=Math.round(s.you.score)+' pts';
      document.getElementById('live-players').textContent=s.players||0;
      if(s.status==='waiting'){ show(elLobby); }
      else if(s.status==='running' && s.question){
        if(s.index!==curIndex){ curIndex=s.index; submitted=false;
          document.getElementById('lq-options').classList.remove('hidden');
          renderQuestion(s.question);
        }
        if(s.question.answered && !submitted){ submitted=true;
          document.getElementById('lq-submit').classList.add('hidden');
          document.getElementById('lq-options').classList.add('hidden');
          document.getElementById('lq-waiting').classList.remove('hidden');
        }
      } else if(s.status==='ended'){
        document.getElementById('live-final').textContent=s.you?Math.round(s.you.score):0;
        renderBoard(s.leaderboard||[],'live-board'); show(elEnded);
        return; // stop polling
      }
      setTimeout(poll,2000);
    }).catch(function(){ setTimeout(poll,3000); });
  }
  poll();
})();
</script>
