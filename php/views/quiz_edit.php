<?php
/** Quiz editor. Expects $quiz, $questions, $types. */
$isForm = $quiz['kind'] === 'form';
$itemWord = $isForm ? 'field' : 'question';
$shareUrl = abs_url('/q/' . $quiz['share_code']);
// Group types for the <select>
$groups = ['choice'=>'Choice','text'=>'Text','scale'=>'Scale & rating','poll'=>'Poll / open',
           'interactive'=>'Interactive','form'=>'Form fields'];
$byGroup = [];
foreach ($types as [$val,$label,$grp]) { $byGroup[$grp][] = [$val,$label]; }
?>
<!-- Header -->
<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-5">
  <div class="min-w-0">
    <a href="<?= e(url('/admin')) ?>" class="text-sm text-slate-500 hover:text-brand-700">&larr; Dashboard</a>
    <h1 class="text-2xl font-bold mt-1 break-words"><?= e($quiz['title']) ?></h1>
    <div class="text-sm text-slate-500 mt-1 flex items-center gap-2 flex-wrap">
      <span>Share code:</span>
      <span class="font-mono bg-slate-100 px-2 py-0.5 rounded"><?= e($quiz['share_code']) ?></span>
      <button type="button" class="qf-btn qf-btn-ghost qf-btn-sm" data-copy="<?= e($shareUrl) ?>" aria-label="Copy public link">Copy link</button>
      <?php if (!$quiz['is_published']): ?><span class="qf-badge qf-badge-warn">Draft — publish in settings</span><?php endif; ?>
    </div>
  </div>
  <div class="flex flex-wrap gap-2 shrink-0">
    <a href="<?= e(url('/q/'.$quiz['share_code'])) ?>" target="_blank" rel="noopener" class="qf-btn qf-btn-secondary qf-btn-sm">Preview</a>
    <a href="<?= e(url('/admin/quizzes/'.$quiz['id'].'/results')) ?>" class="qf-btn qf-btn-secondary qf-btn-sm">Results</a>
    <form method="post" action="<?= e(url('/admin/quizzes/'.$quiz['id'].'/delete')) ?>" onsubmit="return confirm('Delete this quiz and all its results? This cannot be undone.')">
      <?= csrf_field() ?>
      <button class="qf-btn qf-btn-danger qf-btn-sm">Delete</button>
    </form>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <!-- ── Settings ── -->
  <aside class="lg:col-span-1 space-y-4">
    <div class="qf-card qf-card-pad" id="settings-card">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold">Settings</h2>
        <span id="save-status" class="text-xs text-slate-400">Saved automatically</span>
      </div>
      <?= csrf_field() ?>
      <div class="qf-field">
        <label class="qf-label">Title</label>
        <input class="qf-input qf-auto" data-field="title" value="<?= e($quiz['title']) ?>" />
      </div>
      <div class="qf-field">
        <label class="qf-label">Description</label>
        <textarea class="qf-textarea qf-auto" data-field="description" rows="2"><?= e($quiz['description']) ?></textarea>
      </div>
      <div class="qf-field">
        <label class="qf-label">Type</label>
        <select class="qf-select qf-auto" data-field="kind">
          <?php foreach (['exam'=>'Exam / Quiz','poll'=>'Live Poll','survey'=>'Survey','form'=>'Form (collect data)'] as $k=>$lbl): ?>
            <option value="<?= $k ?>" <?= $quiz['kind']===$k?'selected':'' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($quiz['kind']==='exam'): ?>
        <div class="qf-field">
          <label class="qf-label">Whole-exam time limit (seconds, 0 = none)</label>
          <input type="number" min="0" class="qf-input qf-auto" data-field="time_limit_seconds" value="<?= (int)$quiz['time_limit_seconds'] ?>" />
        </div>
        <div class="qf-field">
          <label class="qf-label">Pass mark %</label>
          <input type="number" min="0" max="100" class="qf-input qf-auto" data-field="pass_mark" value="<?= (int)$quiz['pass_mark'] ?>" />
        </div>
      <?php endif; ?>
      <div class="space-y-2 text-sm mt-1">
        <?php
        $toggles = [];
        if ($quiz['kind']==='exam') {
          $toggles = [
            'paginated'=>'One question per page',
            'randomize_questions'=>'Randomize question order',
            'randomize_options'=>'Randomize answer options',
            'show_correct_answers'=>'Show correct answers after submit',
          ];
        }
        if ($quiz['kind']!=='survey') { $toggles['require_name']='Require name'; $toggles['require_email']='Require email'; }
        $toggles['is_published']='Published (visible to takers)';
        foreach ($toggles as $f=>$lbl): ?>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" class="qf-auto-check" data-field="<?= $f ?>" <?= $quiz[$f]?'checked':'' ?> />
            <span><?= e($lbl) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($quiz['kind']==='exam'): ?>
    <details class="qf-card qf-card-pad">
      <summary class="font-semibold cursor-pointer">🛡️ Anti-cheating</summary>
      <div class="mt-3 space-y-2 text-sm">
        <div class="qf-field">
          <label class="qf-label">Quiz password (blank = none)</label>
          <input class="qf-input qf-auto" data-field="quiz_password" value="<?= e($quiz['quiz_password']) ?>" />
        </div>
        <?php foreach (['detect_tab_switch'=>'Detect tab / window switches','require_fullscreen'=>'Require fullscreen','anti_paste'=>'Block copy / paste','anti_rightclick'=>'Block right-click','detect_devtools'=>'Detect dev-tools','camera_proctor'=>'AI camera proctoring'] as $f=>$lbl): ?>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" class="qf-auto-check" data-field="<?= $f ?>" <?= $quiz[$f]?'checked':'' ?> />
            <span><?= e($lbl) ?></span>
          </label>
        <?php endforeach; ?>
        <div class="qf-field">
          <label class="qf-label">Auto-submit after N violations (0 = warn only)</label>
          <input type="number" min="0" class="qf-input qf-auto" data-field="violation_limit" value="<?= (int)$quiz['violation_limit'] ?>" />
        </div>
      </div>
    </details>
    <?php endif; ?>

    <details class="qf-card qf-card-pad">
      <summary class="font-semibold cursor-pointer">📥 Bulk import</summary>
      <form method="post" action="<?= e(url('/admin/quizzes/'.$quiz['id'].'/import')) ?>" enctype="multipart/form-data" class="mt-3 space-y-2 text-sm">
        <?= csrf_field() ?>
        <p class="text-xs text-slate-500">Upload a <b>.csv</b>, <b>.txt</b> or <b>.json</b> file, or paste below.</p>
        <input type="file" name="file" accept=".csv,.txt,.json" class="w-full text-xs" />
        <select name="format" class="qf-select" style="padding:.4rem .6rem">
          <option value="text">Text / Aiken format</option>
          <option value="csv">CSV</option>
          <option value="json">JSON</option>
        </select>
        <textarea name="content" rows="5" class="qf-textarea font-mono text-xs" placeholder="What is 2+2?&#10;A) 3&#10;B) 4&#10;ANSWER: B"></textarea>
        <button class="qf-btn qf-btn-secondary qf-btn-block qf-btn-sm">Import</button>
        <details class="text-xs text-slate-500">
          <summary class="cursor-pointer">Format examples</summary>
          <div class="mt-2 space-y-2">
            <div><b>Text:</b><pre class="bg-slate-50 p-2 rounded mt-1 overflow-auto">What is the capital of France?
A) Berlin
B) Paris
ANSWER: B

The Earth is flat.
ANSWER: False

Q: What does HTTP stand for?
A: Hypertext Transfer Protocol</pre></div>
            <div><b>CSV:</b><pre class="bg-slate-50 p-2 rounded mt-1 overflow-auto">type,text,opt1,opt2,opt3,opt4,correct,points
mcq_single,Capital of France?,Berlin,Madrid,Paris,Rome,C,1
true_false,Earth is flat,,,,,False,1
mcq_multi,Pick primes,2,3,4,5,A|B|D,2</pre></div>
          </div>
        </details>
      </form>
    </details>
  </aside>

  <!-- ── Questions ── -->
  <section class="lg:col-span-2 space-y-3">
    <div class="flex items-center justify-between gap-2">
      <h2 class="font-semibold"><?= $isForm?'Form fields':'Questions' ?> (<span id="q-count"><?= count($questions) ?></span>)</h2>
      <button type="button" id="add-q-btn" class="qf-btn qf-btn-primary qf-btn-sm">+ Add <?= e($itemWord) ?></button>
    </div>

    <!-- Question list -->
    <div id="q-list" class="space-y-3">
      <?php foreach ($questions as $i => $q): ?>
        <?php $qi = $i + 1; include __DIR__ . '/_question_card.php'; ?>
      <?php endforeach; ?>
    </div>
    <?php if (!$questions): ?>
      <div id="empty-q" class="qf-card qf-card-pad text-center py-10 text-slate-500 border-dashed">
        No <?= e($itemWord) ?>s yet. Click <b>+ Add <?= e($itemWord) ?></b> to begin.
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- ── Add / Edit question modal ── -->
<div id="q-modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-start sm:items-center justify-center p-3 overflow-auto">
  <div class="qf-card w-full max-w-xl my-4">
    <div class="flex items-center justify-between px-5 py-3 border-b border-slate-200">
      <h3 class="font-semibold" id="q-modal-title">Add question</h3>
      <button type="button" id="q-modal-close" class="qf-btn qf-btn-ghost qf-btn-sm" aria-label="Close">✕</button>
    </div>
    <form method="post" action="<?= e(url('/admin/quizzes/'.$quiz['id'].'/questions')) ?>" class="p-5 space-y-4" id="q-form">
      <?= csrf_field() ?>
      <input type="hidden" name="qid" id="q-qid" value="" />
      <div class="qf-field">
        <label class="qf-label">Type</label>
        <select class="qf-select" name="type" id="q-type">
          <?php foreach ($groups as $g=>$glabel): if (empty($byGroup[$g])) continue; ?>
            <optgroup label="<?= e($glabel) ?>">
              <?php foreach ($byGroup[$g] as [$val,$label]): ?>
                <option value="<?= e($val) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="qf-field">
        <label class="qf-label" id="q-text-label">Question text</label>
        <textarea class="qf-textarea" name="text" id="q-text" rows="2" required></textarea>
      </div>

      <!-- Choice options (mcq/true_false/dropdown/poll) -->
      <div id="q-choice" class="hidden">
        <label class="qf-label">Options <span class="text-slate-400 font-normal">(mark the correct one/s)</span></label>
        <div id="q-options" class="space-y-2"></div>
        <button type="button" id="q-add-opt" class="qf-btn qf-btn-ghost qf-btn-sm mt-2">+ Add option</button>
      </div>

      <!-- Accepted answers (short/fill) -->
      <div id="q-accepted" class="hidden qf-field">
        <label class="qf-label">Accepted answers <span class="text-slate-400 font-normal">(one per line)</span></label>
        <textarea class="qf-textarea" name="accepted" id="q-accepted-ta" rows="3" placeholder="Paris&#10;paris, france"></textarea>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="qf-field" id="q-points-wrap">
          <label class="qf-label">Points</label>
          <input type="number" min="0" step="1" class="qf-input" name="points" id="q-points" value="1" />
        </div>
        <div class="qf-field">
          <label class="qf-label">Time limit (sec, 0=none)</label>
          <input type="number" min="0" step="5" class="qf-input" name="time_limit_seconds" id="q-time" value="0" />
        </div>
      </div>
      <div class="qf-field">
        <label class="qf-label">Explanation <span class="text-slate-400 font-normal">(optional, shown after)</span></label>
        <input class="qf-input" name="explanation" id="q-expl" />
      </div>
      <label class="flex items-center gap-2 text-sm cursor-pointer">
        <input type="checkbox" name="is_required" value="1" id="q-required" checked /> Required
      </label>

      <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
        <button type="button" id="q-cancel" class="qf-btn qf-btn-secondary">Cancel</button>
        <button type="submit" class="qf-btn qf-btn-primary">Save <?= e($itemWord) ?></button>
      </div>
    </form>
  </div>
</div>

<script>
window.QF_QUESTIONS = <?= json_encode(array_map(function($q){
  return ['id'=>(int)$q['id'],'type'=>$q['type'],'text'=>$q['text'],'options'=>$q['options'],
          'correct_answers'=>array_values(array_map('intval',(array)$q['correct_answers'])),
          'points'=>(int)$q['points'],'explanation'=>$q['explanation'],
          'time_limit_seconds'=>(int)$q['time_limit_seconds'],'is_required'=>(int)$q['is_required']];
}, $questions), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/assets/js/quiz-editor.js')) ?>"></script>
