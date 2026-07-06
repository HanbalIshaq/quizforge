<?php
/** Student take page. Expects $quiz, $questions. Single-page form. */
$isExam = $quiz['kind'] === 'exam';
$isSurvey = $quiz['kind'] === 'survey';
$askName = !$isSurvey && $quiz['require_name'];
$askEmail = $quiz['require_email'];
$n = 0;
?>
<div class="mb-5">
  <h1 class="text-2xl font-bold"><?= e($quiz['title']) ?></h1>
  <?php if (!empty($quiz['description'])): ?>
    <p class="text-slate-600 mt-1"><?= nl2br(e($quiz['description'])) ?></p>
  <?php endif; ?>
  <p class="text-sm text-slate-500 mt-2">
    <?= count(array_filter($questions, fn($q)=>$q['type']!=='section_break')) ?> question(s)
    <?php if ($isExam && $quiz['time_limit_seconds']): ?> · <span id="timer" class="font-semibold text-brand-700"></span> left<?php endif; ?>
  </p>
</div>

<form method="post" action="<?= e(url('/q/'.$quiz['share_code'])) ?>" id="take-form" enctype="multipart/form-data"
      data-code="<?= e($quiz['share_code']) ?>"
      data-attempt="<?= (int)($attemptId ?? 0) ?>"
      data-base="<?= e(url('')) ?>"
      <?php if ($isExam): ?>
      data-detect-tab="<?= (int)$quiz['detect_tab_switch'] ?>"
      data-fullscreen="<?= (int)$quiz['require_fullscreen'] ?>"
      data-anti-paste="<?= (int)$quiz['anti_paste'] ?>"
      data-anti-rightclick="<?= (int)$quiz['anti_rightclick'] ?>"
      data-detect-devtools="<?= (int)$quiz['detect_devtools'] ?>"
      data-violation-limit="<?= (int)$quiz['violation_limit'] ?>"
      data-camera="<?= (int)$quiz['camera_proctor'] ?>"
      data-snap-interval="<?= (int)$quiz['proctor_snapshot_interval'] ?>"
      <?php endif; ?>>
  <?php
    $paginated = !empty($quiz['paginated']);
    $submitLabel = $isSurvey ? 'Submit response' : ($quiz['kind']==='poll' ? 'Submit vote' : 'Submit answers');
    // Build the ordered list of "steps": optional name/email step, then one
    // step per question (section breaks ride along with the next question).
    $stepClass = $paginated ? 'qf-step' : 'qf-step mb-4';
  ?>
  <div id="qf-steps" class="<?= $paginated ? '' : 'space-y-4' ?>" data-paginated="<?= $paginated ? '1' : '0' ?>">
    <?php if ($askName || $askEmail): ?>
      <div class="<?= $stepClass ?>">
        <div class="qf-card qf-card-pad">
          <?php if ($askName): ?>
            <div class="qf-field" data-required="1"><label class="qf-label">Your name <span class="text-red-500">*</span></label>
              <input class="qf-input" name="student_name" required autocomplete="name" /></div>
          <?php endif; ?>
          <?php if ($askEmail): ?>
            <div class="qf-field" data-required="1" style="margin-bottom:0"><label class="qf-label">Email <span class="text-red-500">*</span></label>
              <input class="qf-input" type="email" name="student_email" required autocomplete="email" inputmode="email" /></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php
      // Group a leading section break with the question that follows it.
      $pendingSection = null;
      foreach ($questions as $q):
        if ($q['type'] === 'section_break') { $pendingSection = $q; continue; }
        $n++;
    ?>
      <div class="<?= $stepClass ?>">
        <?php if ($pendingSection): ?>
          <div class="mb-3">
            <h2 class="text-lg font-bold text-slate-800"><?= e($pendingSection['text']) ?></h2>
            <?php if (!empty($pendingSection['explanation'])): ?><p class="text-sm text-slate-500 mt-1"><?= e($pendingSection['explanation']) ?></p><?php endif; ?>
          </div>
          <?php $pendingSection = null; ?>
        <?php endif; ?>
        <div class="qf-card qf-card-pad">
          <div class="font-medium mb-3">
            <span class="text-slate-400 mr-1"><?= $n ?>.</span><?= e($q['text']) ?>
            <?php if (!empty($q['is_required'])): ?><span class="text-red-500">*</span><?php endif; ?>
            <?php if ($isExam && $q['points'] != 1): ?><span class="text-xs text-slate-400 font-normal">(<?= (int)$q['points'] ?> pts)</span><?php endif; ?>
          </div>
          <?= render_take_question($q) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($paginated): ?>
    <div class="mt-6 flex items-center justify-between gap-3">
      <button type="button" id="qf-prev" class="qf-btn qf-btn-secondary" style="visibility:hidden">← Back</button>
      <span id="qf-progress" class="text-sm text-slate-500"></span>
      <button type="button" id="qf-next" class="qf-btn qf-btn-primary">Next →</button>
      <button type="submit" id="submit-btn" class="qf-btn qf-btn-primary qf-btn-lg hidden"><?= $submitLabel ?></button>
    </div>
  <?php else: ?>
    <div class="mt-6 flex justify-end">
      <button type="submit" id="submit-btn" class="qf-btn qf-btn-primary qf-btn-lg"><?= $submitLabel ?></button>
    </div>
  <?php endif; ?>
</form>

<!-- Submitting overlay (instant feedback, prevents double-submit) -->
<div id="submitting" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center">
  <div class="qf-card qf-card-pad text-center" style="max-width:20rem">
    <div class="mx-auto mb-3 w-10 h-10 border-4 border-brand-100 border-t-brand-600 rounded-full animate-spin"></div>
    <p class="font-semibold">Submitting…</p>
    <p class="text-sm text-slate-500 mt-1">Please don't close this page.</p>
  </div>
</div>

<script>
(function(){
  var form=document.getElementById('take-form'), submitting=false;
  // star + nps visual selection
  document.querySelectorAll('.qf-star').forEach(function(inp){
    inp.addEventListener('change',function(){
      var wrap=inp.closest('div'); var v=parseInt(inp.value,10);
      wrap.querySelectorAll('[data-star]').forEach(function(s){ s.style.color = (parseInt(s.dataset.star,10)<=v)?'#f59e0b':''; });
    });
  });
  document.querySelectorAll('.qf-nps').forEach(function(inp){
    inp.addEventListener('change',function(){
      var wrap=inp.closest('div');
      wrap.querySelectorAll('[data-nps]').forEach(function(s){ s.style.background=''; s.style.color=''; });
      var box=inp.nextElementSibling; box.style.background='#4f46e5'; box.style.color='#fff';
    });
  });
  // Return the first unanswered required field within `root` (or null).
  // data-required may be on a WRAPPER (radios/checkboxes) OR directly on the
  // field itself (dropdown/text/number/textarea) — handle both.
  function firstMissing(root){
    var miss=null;
    root.querySelectorAll('[data-required="1"]').forEach(function(block){
      if(miss) return;
      var tag=block.tagName.toLowerCase();
      var inputs=(tag==='input'||tag==='select'||tag==='textarea')
        ? [block]
        : Array.prototype.slice.call(block.querySelectorAll('input,select,textarea'));
      var ok=false;
      inputs.forEach(function(i){
        if(i.type==='radio'||i.type==='checkbox'){ if(i.checked) ok=true; }
        else if(i.value && String(i.value).trim()!=='') ok=true;
      });
      if(!ok) miss=block;
    });
    return miss;
  }
  function flagMissing(block){
    block.scrollIntoView({behavior:'smooth',block:'center'});
    block.classList.add('qf-shake'); setTimeout(function(){block.classList.remove('qf-shake');},500);
  }

  // ── Paginated mode: one step at a time ──
  var stepsWrap=document.getElementById('qf-steps');
  var paginated = stepsWrap && stepsWrap.dataset.paginated==='1';
  if(paginated){
    var steps=Array.prototype.slice.call(stepsWrap.querySelectorAll('.qf-step'));
    var cur=0;
    var prevBtn=document.getElementById('qf-prev'), nextBtn=document.getElementById('qf-next'),
        subBtn=document.getElementById('submit-btn'), prog=document.getElementById('qf-progress');
    function showStep(i){
      steps.forEach(function(s,idx){ s.classList.toggle('hidden', idx!==i); });
      cur=i;
      prevBtn.style.visibility = i>0 ? 'visible':'hidden';
      var last = i===steps.length-1;
      nextBtn.classList.toggle('hidden', last);
      subBtn.classList.toggle('hidden', !last);
      prog.textContent = 'Step '+(i+1)+' of '+steps.length;
      window.scrollTo({top:0,behavior:'smooth'});
    }
    nextBtn.addEventListener('click',function(){
      var miss=firstMissing(steps[cur]);
      if(miss){ flagMissing(miss); return; }
      if(cur<steps.length-1) showStep(cur+1);
    });
    prevBtn.addEventListener('click',function(){ if(cur>0) showStep(cur-1); });
    showStep(0);
  }

  // required validation on submit (whole form — covers non-paginated + a
  // final safety check in paginated mode)
  form.addEventListener('submit',function(e){
    if(submitting){e.preventDefault();return;}
    var missing=firstMissing(form);
    if(missing){
      e.preventDefault();
      if(paginated){
        // jump to the step containing the missing field
        var stepEl=missing.closest('.qf-step');
        var idx=Array.prototype.indexOf.call(document.getElementById('qf-steps').querySelectorAll('.qf-step'), stepEl);
        if(idx>=0){ document.getElementById('qf-steps').querySelectorAll('.qf-step').forEach(function(s,i){s.classList.toggle('hidden',i!==idx);}); }
      }
      flagMissing(missing);
      return;
    }
    submitting=true;
    document.getElementById('submit-btn').disabled=true;
    document.getElementById('submitting').classList.remove('hidden');
  });
  <?php if ($isExam && $quiz['time_limit_seconds']): ?>
  var left=<?= (int)$quiz['time_limit_seconds'] ?>, tEl=document.getElementById('timer');
  function fmt(s){return Math.floor(s/60)+':'+String(s%60).padStart(2,'0');}
  tEl.textContent=fmt(left);
  var iv=setInterval(function(){
    left--; if(left<=0){clearInterval(iv); if(!submitting){submitting=true;document.getElementById('submitting').classList.remove('hidden');form.submit();} return;}
    tEl.textContent=fmt(left); if(left<=30)tEl.classList.add('text-red-600');
  },1000);
  <?php endif; ?>
})();
</script>

<?php if ($isExam && ($quiz['detect_tab_switch'] || $quiz['require_fullscreen'] || $quiz['anti_paste'] || $quiz['anti_rightclick'] || $quiz['camera_proctor'])): ?>
<!-- Anti-cheat enforcement -->
<div id="ac-banner" class="hidden fixed top-0 inset-x-0 z-50 bg-red-600 text-white text-sm text-center py-2 px-4"></div>
<?php if ($quiz['camera_proctor']): ?>
<div id="cam-wrap" class="fixed bottom-3 right-3 z-40 bg-black rounded-lg overflow-hidden shadow-lg" style="width:150px">
  <video id="cam" autoplay muted playsinline class="w-full block"></video>
  <div class="text-[10px] text-white text-center bg-black/70 py-0.5">● Proctoring</div>
</div>
<canvas id="cam-canvas" class="hidden"></canvas>
<?php endif; ?>
<script>
(function(){
  var form = document.getElementById('take-form');
  var d = form.dataset;
  var base = d.base || '';
  var code = d.code, attempt = d.attempt;
  var limit = parseInt(d.violationLimit||'0',10);
  var vcount = 0, submitting = false;
  var banner = document.getElementById('ac-banner');

  function markSubmitting(){ // mirror the submit overlay used by the main script
    submitting = true;
    var ov=document.getElementById('submitting'); if(ov) ov.classList.remove('hidden');
  }
  function showBanner(msg){ banner.textContent=msg; banner.classList.remove('hidden'); setTimeout(function(){banner.classList.add('hidden');},4000); }

  function report(type, details){
    try {
      fetch(base + '/q/' + code + '/violation', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({attempt_id: attempt, type: type, details: details||''})
      }).then(function(r){return r.json();}).then(function(j){
        if(!j || !j.ok) return;
        vcount = j.count;
        var extra = j.limit>0 ? ' ('+j.count+'/'+j.limit+')' : '';
        showBanner('⚠ Integrity warning: ' + type.replace('_',' ') + extra);
        if(j.auto_submit && !submitting){
          markSubmitting();
          alert('Too many integrity violations — your quiz will be submitted now.');
          form.submit();
        }
      }).catch(function(){});
    } catch(e){}
  }
  // let the main submit handler set submitting; detect it via the overlay
  form.addEventListener('submit', function(){ submitting = true; });

  // Tab / window switching
  if(d.detectTab==='1'){
    document.addEventListener('visibilitychange', function(){ if(document.hidden && !submitting) report('tab_switch','left the tab'); });
    window.addEventListener('blur', function(){ if(!submitting) report('window_blur','window lost focus'); });
  }
  // Block paste / copy / cut
  if(d.antiPaste==='1'){
    ['paste','copy','cut'].forEach(function(ev){
      document.addEventListener(ev, function(e){ if(!submitting){ e.preventDefault(); report(ev,'blocked '+ev); } });
    });
  }
  // Block right-click
  if(d.antiRightclick==='1'){
    document.addEventListener('contextmenu', function(e){ e.preventDefault(); if(!submitting) report('rightclick','right-click blocked'); });
  }
  // Require fullscreen
  if(d.fullscreen==='1'){
    var goFs = function(){ var el=document.documentElement; (el.requestFullscreen||el.webkitRequestFullscreen||function(){}).call(el); };
    // Prompt to enter fullscreen on first interaction
    var fsBtn = document.createElement('div');
    fsBtn.className='fixed inset-0 z-50 bg-slate-900/80 flex items-center justify-center p-4';
    fsBtn.innerHTML='<div class="qf-card qf-card-pad text-center" style="max-width:22rem"><p class="font-semibold mb-2">Fullscreen required</p><p class="text-sm text-slate-500 mb-4">This exam must be taken in fullscreen. Click below to begin.</p><button type="button" class="qf-btn qf-btn-primary">Enter fullscreen &amp; start</button></div>';
    document.body.appendChild(fsBtn);
    fsBtn.querySelector('button').addEventListener('click', function(){ goFs(); fsBtn.remove(); });
    document.addEventListener('fullscreenchange', function(){ if(!document.fullscreenElement && !submitting) report('fullscreen_exit','left fullscreen'); });
  }

  <?php if ($quiz['camera_proctor']): ?>
  // Camera proctoring: request webcam, show preview, capture periodic snapshots
  (function(){
    var video=document.getElementById('cam'), canvas=document.getElementById('cam-canvas');
    var interval = Math.max(15, parseInt(d.snapInterval||'30',10)) * 1000;
    navigator.mediaDevices && navigator.mediaDevices.getUserMedia({video:{width:320,height:240},audio:false})
      .then(function(stream){
        video.srcObject = stream;
        function snap(kind){
          if(!video.videoWidth) return;
          canvas.width=320; canvas.height=240;
          canvas.getContext('2d').drawImage(video,0,0,320,240);
          var data = canvas.toDataURL('image/jpeg', 0.5);
          fetch(base + '/q/' + code + '/proctor', {method:'POST',headers:{'Content-Type':'application/json'},
            body: JSON.stringify({attempt_id:attempt, image:data, kind:kind})}).catch(function(){});
        }
        setTimeout(function(){snap('baseline');}, 2000);
        setInterval(function(){ if(!submitting) snap('periodic'); }, interval);
      })
      .catch(function(){
        showBanner('⚠ This exam requires camera access. Please allow your webcam.');
      });
  })();
  <?php endif; ?>
})();
</script>
<?php endif; ?>
