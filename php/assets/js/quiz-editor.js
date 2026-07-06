/* Quizly quiz editor — modal add/edit + settings auto-save. Vanilla JS. */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  // ── Settings auto-save (fetch on change, debounced for text inputs) ──
  var card = document.getElementById('settings-card');
  var status = document.getElementById('save-status');
  if (card) {
    var settingsUrl = window.location.pathname.replace(/\/$/, '') + '/settings';
    var timer = null;
    function saveField(field, value) {
      if (status) { status.textContent = 'Saving…'; status.className = 'text-xs text-amber-600'; }
      var body = new URLSearchParams();
      body.set(field, value);
      body.set('_csrf', csrf);
      fetch(settingsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function () {
        if (status) { status.textContent = 'Saved ✓'; status.className = 'text-xs text-emerald-600'; }
      }).catch(function () {
        if (status) { status.textContent = 'Save failed'; status.className = 'text-xs text-red-600'; }
      });
    }
    card.querySelectorAll('.qf-auto').forEach(function (el) {
      el.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { saveField(el.dataset.field, el.value); }, 600);
      });
      el.addEventListener('change', function () {
        clearTimeout(timer);
        saveField(el.dataset.field, el.value);
      });
    });
    card.querySelectorAll('.qf-auto-check').forEach(function (el) {
      el.addEventListener('change', function () { saveField(el.dataset.field, el.checked ? '1' : '0'); });
    });
  }

  // ── Question modal ──
  var modal = document.getElementById('q-modal');
  if (!modal) return;
  var typeSel = document.getElementById('q-type');
  var choiceBox = document.getElementById('q-choice');
  var optionsBox = document.getElementById('q-options');
  var acceptedBox = document.getElementById('q-accepted');
  var pointsWrap = document.getElementById('q-points-wrap');
  var textLabel = document.getElementById('q-text-label');
  var modalTitle = document.getElementById('q-modal-title');

  var CHOICE = ['mcq_single', 'mcq_multi', 'true_false', 'dropdown', 'poll'];
  var ACCEPTED = ['short_answer', 'fill_blank'];
  var MULTI = ['mcq_multi'];
  var UNGRADED = ['poll', 'open_ended', 'word_cloud', 'rating', 'nps', 'slider', 'email', 'phone',
                  'number', 'date', 'time', 'datetime', 'url', 'address', 'full_name', 'file_upload',
                  'signature', 'consent', 'section_break'];

  function optionRow(value, checked, isMulti) {
    var wrap = document.createElement('div');
    wrap.className = 'flex items-center gap-2';
    var inputType = isMulti ? 'checkbox' : 'radio';
    wrap.innerHTML =
      '<input type="' + inputType + '" name="correct[]" class="q-correct" title="Mark correct" />' +
      '<input type="text" name="options[]" class="qf-input" placeholder="Option text" />' +
      '<button type="button" class="qf-btn qf-btn-ghost qf-btn-sm q-del-opt" aria-label="Remove option">✕</button>';
    var opt = wrap.querySelector('input[name="options[]"]');
    var cor = wrap.querySelector('.q-correct');
    opt.value = value || '';
    if (checked) cor.checked = true;
    wrap.querySelector('.q-del-opt').addEventListener('click', function () { wrap.remove(); reindexCorrect(); });
    return wrap;
  }

  // correct[] values must map to the option index; we use the DOM order
  function reindexCorrect() {
    optionsBox.querySelectorAll('.q-correct').forEach(function (c, i) { c.value = String(i); });
  }

  function setOptionMode(isMulti) {
    optionsBox.querySelectorAll('.q-correct').forEach(function (c) {
      c.type = isMulti ? 'checkbox' : 'radio';
      c.name = 'correct[]';
    });
  }

  function addOption(value, checked) {
    var isMulti = MULTI.indexOf(typeSel.value) !== -1;
    optionsBox.appendChild(optionRow(value, checked, isMulti));
    reindexCorrect();
  }

  function refreshType() {
    var t = typeSel.value;
    var isChoice = CHOICE.indexOf(t) !== -1;
    var isAccepted = ACCEPTED.indexOf(t) !== -1;
    choiceBox.classList.toggle('hidden', !isChoice);
    acceptedBox.classList.toggle('hidden', !isAccepted);
    pointsWrap.style.display = (UNGRADED.indexOf(t) !== -1) ? 'none' : '';
    textLabel.textContent = (t === 'section_break') ? 'Section text / instructions' : 'Question text';
    setOptionMode(MULTI.indexOf(t) !== -1);
    if (isChoice && optionsBox.children.length === 0) {
      if (t === 'true_false') { addOption('True', false); addOption('False', false); }
      else { addOption('', false); addOption('', false); }
    }
  }

  typeSel.addEventListener('change', refreshType);
  document.getElementById('q-add-opt').addEventListener('click', function () { addOption('', false); });

  function openModal(editing) {
    modal.classList.remove('hidden');
    modalTitle.textContent = editing ? 'Edit question' : 'Add question';
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.classList.add('hidden');
    document.body.style.overflow = '';
  }
  function resetForm() {
    document.getElementById('q-qid').value = '';
    document.getElementById('q-text').value = '';
    document.getElementById('q-accepted-ta').value = '';
    document.getElementById('q-points').value = '1';
    document.getElementById('q-time').value = '0';
    document.getElementById('q-expl').value = '';
    document.getElementById('q-required').checked = true;
    optionsBox.innerHTML = '';
    typeSel.value = 'mcq_single';
    refreshType();
  }

  document.getElementById('add-q-btn').addEventListener('click', function () {
    resetForm(); openModal(false);
  });
  document.getElementById('q-cancel').addEventListener('click', closeModal);
  document.getElementById('q-modal-close').addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });

  // Edit — populate from window.QF_QUESTIONS
  function bindEdit(btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.dataset.qid, 10);
      var q = (window.QF_QUESTIONS || []).find(function (x) { return x.id === id; });
      if (!q) return;
      resetForm();
      typeSel.value = q.type;
      refreshType();
      document.getElementById('q-qid').value = q.id;
      document.getElementById('q-text').value = q.text || '';
      document.getElementById('q-points').value = q.points || 1;
      document.getElementById('q-time').value = q.time_limit_seconds || 0;
      document.getElementById('q-expl').value = q.explanation || '';
      document.getElementById('q-required').checked = !!q.is_required;
      if (CHOICE.indexOf(q.type) !== -1) {
        optionsBox.innerHTML = '';
        (q.options || []).forEach(function (opt, i) {
          addOption(opt, (q.correct_answers || []).indexOf(i) !== -1);
        });
      } else if (ACCEPTED.indexOf(q.type) !== -1) {
        document.getElementById('q-accepted-ta').value = (q.correct_answers || []).join('\n');
      }
      openModal(true);
    });
  }
  document.querySelectorAll('.q-edit').forEach(bindEdit);

  // Ensure correct[] indices are fresh right before submit
  document.getElementById('q-form').addEventListener('submit', function () { reindexCorrect(); });
})();
