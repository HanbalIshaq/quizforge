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

<form method="post" action="<?= e(url('/q/'.$quiz['share_code'])) ?>" id="take-form" enctype="multipart/form-data">
  <?php if ($askName || $askEmail): ?>
    <div class="qf-card qf-card-pad mb-4">
      <?php if ($askName): ?>
        <div class="qf-field"><label class="qf-label">Your name <span class="text-red-500">*</span></label>
          <input class="qf-input" name="student_name" required autocomplete="name" /></div>
      <?php endif; ?>
      <?php if ($askEmail): ?>
        <div class="qf-field" style="margin-bottom:0"><label class="qf-label">Email <span class="text-red-500">*</span></label>
          <input class="qf-input" type="email" name="student_email" required autocomplete="email" inputmode="email" /></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="space-y-4">
    <?php foreach ($questions as $q): ?>
      <?php if ($q['type'] === 'section_break'): ?>
        <div class="pt-2">
          <h2 class="text-lg font-bold text-slate-800"><?= e($q['text']) ?></h2>
          <?php if (!empty($q['explanation'])): ?><p class="text-sm text-slate-500 mt-1"><?= e($q['explanation']) ?></p><?php endif; ?>
        </div>
      <?php else: $n++; ?>
        <div class="qf-card qf-card-pad">
          <div class="font-medium mb-3">
            <span class="text-slate-400 mr-1"><?= $n ?>.</span><?= e($q['text']) ?>
            <?php if (!empty($q['is_required'])): ?><span class="text-red-500">*</span><?php endif; ?>
            <?php if ($isExam && $q['points'] != 1): ?><span class="text-xs text-slate-400 font-normal">(<?= (int)$q['points'] ?> pts)</span><?php endif; ?>
          </div>
          <?= render_take_question($q) ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="mt-6 flex justify-end">
    <button type="submit" id="submit-btn" class="qf-btn qf-btn-primary qf-btn-lg">Submit <?= $isSurvey?'response':($quiz['kind']==='poll'?'vote':'answers') ?></button>
  </div>
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
  // required validation (client)
  form.addEventListener('submit',function(e){
    if(submitting){e.preventDefault();return;}
    var missing=null;
    form.querySelectorAll('[data-required="1"]').forEach(function(block){
      if(missing) return;
      var inputs=block.querySelectorAll('input,select,textarea');
      var ok=false;
      inputs.forEach(function(i){
        if((i.type==='radio'||i.type==='checkbox')){ if(i.checked) ok=true; }
        else if(i.value && i.value.trim()!=='') ok=true;
      });
      if(!ok) missing=block;
    });
    if(missing){
      e.preventDefault();
      missing.scrollIntoView({behavior:'smooth',block:'center'});
      missing.classList.add('qf-shake'); setTimeout(function(){missing.classList.remove('qf-shake');},500);
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
