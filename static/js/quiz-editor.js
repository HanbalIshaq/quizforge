// Inline editor for quiz questions.
(function () {
  const list = document.getElementById('questions-list');
  const emptyMsg = document.getElementById('empty-msg');
  const addBtn = document.getElementById('add-q-btn');
  const QUESTION_TYPES = JSON.parse(document.getElementById('question-types').textContent);

  const quizIdMatch = location.pathname.match(/\/admin\/quizzes\/(\d+)/);
  const quizId = quizIdMatch ? quizIdMatch[1] : null;

  function buildEditor(existing) {
    const data = existing || { type: 'mcq_single', text: '', options: ['', ''], correct_answers: [], points: 1, explanation: '' };
    const wrap = document.createElement('div');
    wrap.className = 'bg-white border-2 border-brand-500 rounded-lg p-4 fade-in question-editor';
    wrap.innerHTML = `
      <div class="grid md:grid-cols-3 gap-2 mb-3">
        <select class="q-type px-2 py-2 border rounded text-sm">
          ${QUESTION_TYPES.map(([v,l]) => `<option value="${v}">${l}</option>`).join('')}
        </select>
        <input class="q-points px-3 py-2 border rounded text-sm" type="number" min="0" value="${data.points || 1}" placeholder="Points" />
        <div></div>
      </div>
      <textarea class="q-text w-full px-3 py-2 border rounded text-sm mb-2" rows="2" placeholder="Question text...">${esc(data.text)}</textarea>
      <div class="q-options-wrap"></div>
      <div class="q-short-wrap mt-2 hidden">
        <input class="q-short w-full px-3 py-2 border rounded text-sm" placeholder="Correct answer(s), separate with |" />
      </div>
      <input class="q-expl mt-3 w-full px-3 py-2 border rounded text-sm" placeholder="Explanation (optional)" value="${esc(data.explanation || '')}" />
      <div class="mt-3 flex justify-end gap-2">
        <button class="q-cancel px-3 py-1.5 bg-slate-100 text-sm rounded hover:bg-slate-200">Cancel</button>
        <button class="q-save px-3 py-1.5 bg-brand-600 text-white text-sm rounded hover:bg-brand-700">Save</button>
      </div>
    `;
    const typeSel = wrap.querySelector('.q-type');
    typeSel.value = data.type;
    const optsWrap = wrap.querySelector('.q-options-wrap');
    const shortWrap = wrap.querySelector('.q-short-wrap');
    const shortInput = wrap.querySelector('.q-short');

    function render() {
      const t = typeSel.value;
      optsWrap.innerHTML = '';
      shortWrap.classList.add('hidden');
      if (['mcq_single', 'mcq_multi', 'poll'].includes(t)) {
        const opts = (data.options && data.options.length) ? data.options : ['', ''];
        opts.forEach((opt, i) => addOptionRow(opt, (data.correct_answers || []).includes(i), t));
        const addOpt = document.createElement('button');
        addOpt.textContent = '+ option';
        addOpt.className = 'text-xs text-brand-700 hover:underline mt-1';
        addOpt.onclick = (e) => { e.preventDefault(); addOptionRow('', false, typeSel.value); };
        optsWrap.appendChild(addOpt);
      } else if (t === 'true_false') {
        ['True', 'False'].forEach((label, i) => {
          const row = document.createElement('label');
          row.className = 'flex items-center gap-2 p-2 border rounded text-sm';
          row.innerHTML = `<input type="radio" name="tf-correct" value="${i}" ${(data.correct_answers||[]).includes(i) ? 'checked' : ''}/> ${label}`;
          optsWrap.appendChild(row);
        });
      } else if (['short_answer', 'fill_blank'].includes(t)) {
        shortWrap.classList.remove('hidden');
        shortInput.value = (data.correct_answers || []).join(' | ');
      }
    }

    function addOptionRow(text, isCorrect, currentType) {
      const row = document.createElement('div');
      row.className = 'flex items-center gap-2 mb-1';
      const inputType = currentType === 'mcq_multi' ? 'checkbox' : 'radio';
      row.innerHTML = `
        <input type="${inputType}" name="opt-correct" ${isCorrect ? 'checked' : ''} />
        <input type="text" value="${esc(text)}" placeholder="Option text..." class="flex-1 px-2 py-1.5 border rounded text-sm" />
        <button class="text-red-600 text-xs hover:underline">remove</button>
      `;
      row.querySelector('button').onclick = (e) => { e.preventDefault(); row.remove(); };
      optsWrap.appendChild(row);
    }

    typeSel.onchange = render;
    render();

    wrap.querySelector('.q-cancel').onclick = () => wrap.remove();
    wrap.querySelector('.q-save').onclick = async () => {
      const payload = {
        id: data.id,
        type: typeSel.value,
        text: wrap.querySelector('.q-text').value.trim(),
        points: parseInt(wrap.querySelector('.q-points').value || '1', 10),
        explanation: wrap.querySelector('.q-expl').value,
        options: [],
        correct_answers: [],
      };
      if (!payload.text) { alert('Question text is required.'); return; }
      const t = payload.type;
      if (['mcq_single', 'mcq_multi', 'poll'].includes(t)) {
        const rows = optsWrap.querySelectorAll('div.flex');
        rows.forEach((row, i) => {
          const txt = row.querySelector('input[type="text"]').value.trim();
          if (txt) {
            payload.options.push(txt);
            if (row.querySelector('input[type="radio"], input[type="checkbox"]').checked) {
              payload.correct_answers.push(payload.options.length - 1);
            }
          }
        });
        if (payload.options.length < 2) { alert('At least 2 options required.'); return; }
      } else if (t === 'true_false') {
        payload.options = ['True', 'False'];
        const sel = optsWrap.querySelector('input[name="tf-correct"]:checked');
        if (sel) payload.correct_answers = [parseInt(sel.value, 10)];
      } else if (['short_answer', 'fill_blank'].includes(t)) {
        payload.correct_answers = shortInput.value.split('|').map(s => s.trim()).filter(Boolean);
      }
      const res = await fetch(`/admin/quizzes/${quizId}/questions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const json = await res.json();
      if (!json.ok) { alert(json.error || 'Save failed.'); return; }
      window.location.reload();
    };
    return wrap;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  addBtn.onclick = () => {
    emptyMsg && emptyMsg.classList.add('hidden');
    list.appendChild(buildEditor(null));
  };

  document.querySelectorAll('.delete-q-btn').forEach((btn) => {
    btn.onclick = async () => {
      if (!confirm('Delete this question?')) return;
      const card = btn.closest('.question-card');
      const qid = card.dataset.qid;
      await fetch(`/admin/quizzes/${quizId}/questions/${qid}/delete`, { method: 'POST' });
      window.location.reload();
    };
  });

  document.querySelectorAll('.edit-q-btn').forEach((btn) => {
    btn.onclick = async () => {
      const card = btn.closest('.question-card');
      const qid = card.dataset.qid;
      // Pull data from page (we don't have a /api endpoint for one question, so re-fetch the edit page is overkill).
      // Easiest: get the raw via a quick parse. For simplicity, reload after editing instead — and just open empty editor pre-filled by re-reading visible card text isn't reliable.
      // Workaround: fetch all questions list via a JSON endpoint -- but we don't have one. Use embedded data on page.
      const data = window.QF_QUESTIONS_BY_ID && window.QF_QUESTIONS_BY_ID[qid];
      if (!data) { alert('Reload the page and try again.'); return; }
      const editor = buildEditor(data);
      card.replaceWith(editor);
    };
  });
})();
