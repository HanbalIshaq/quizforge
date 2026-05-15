// Auto-save quiz settings — fields tagged data-field inside data-autosave containers.
(function () {
  const quizIdMatch = location.pathname.match(/\/admin\/quizzes\/(\d+)/);
  if (!quizIdMatch) return;
  const quizId = quizIdMatch[1];
  const indicator = document.getElementById('settings-save-status');
  let timer = null;
  let pending = 0;

  function setStatus(text, color) {
    if (!indicator) return;
    indicator.textContent = text;
    indicator.classList.remove('text-emerald-600', 'text-amber-600', 'text-red-600', 'text-slate-400');
    indicator.classList.add(color || 'text-slate-400');
  }

  function saveField(field, value) {
    pending++;
    setStatus('Saving…', 'text-amber-600');
    fetch(`/admin/quizzes/${quizId}/setting`, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ field, value }),
      keepalive: true,
    }).then(r => r.json()).then(j => {
      pending--;
      if (j.ok) {
        if (pending === 0) {
          const now = new Date();
          setStatus('✓ Saved at ' + now.toLocaleTimeString(), 'text-emerald-600');
        }
      } else {
        setStatus('✗ ' + (j.error || 'Save failed'), 'text-red-600');
      }
    }).catch(() => {
      pending--;
      setStatus('✗ Offline', 'text-red-600');
    });
  }

  function readField(el) {
    if (el.type === 'checkbox') return el.checked;
    if (el.type === 'number') return el.value === '' ? 0 : parseInt(el.value, 10);
    return el.value;
  }

  function handleChange(el) {
    const field = el.dataset.field;
    if (!field) return;
    // Debounce text-typing so we don't hammer the server on every keystroke
    const isTyping = (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'number' || el.type === 'password' || el.type === 'email')) || el.tagName === 'TEXTAREA';
    const delay = (el.type === 'checkbox' || el.tagName === 'SELECT') ? 0 : (isTyping ? 600 : 300);
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => saveField(field, readField(el)), delay);
  }

  document.querySelectorAll('[data-autosave="1"]').forEach(container => {
    container.addEventListener('input', e => {
      if (e.target.dataset && e.target.dataset.field) handleChange(e.target);
    });
    container.addEventListener('change', e => {
      if (e.target.dataset && e.target.dataset.field) handleChange(e.target);
    });
  });
})();

// Inline editor for quiz questions.
(function () {
  const list = document.getElementById('questions-list');
  const emptyMsg = document.getElementById('empty-msg');
  const addBtn = document.getElementById('add-q-btn');
  const QUESTION_TYPES = JSON.parse(document.getElementById('question-types').textContent);

  const quizIdMatch = location.pathname.match(/\/admin\/quizzes\/(\d+)/);
  const quizId = quizIdMatch ? quizIdMatch[1] : null;

  function buildEditor(existing) {
    const data = existing || { type: 'mcq_single', text: '', options: ['', ''], correct_answers: [], points: 1, explanation: '', time_limit_seconds: 0 };
    const wrap = document.createElement('div');
    wrap.className = 'bg-white border-2 border-brand-500 rounded-lg p-4 fade-in question-editor';
    wrap.innerHTML = `
      <div class="grid md:grid-cols-3 gap-2 mb-3">
        <select class="q-type px-2 py-2 border rounded text-sm">
          ${QUESTION_TYPES.map(([v,l]) => `<option value="${v}">${l}</option>`).join('')}
        </select>
        <label class="text-xs text-slate-600">Points
          <input class="q-points w-full mt-1 px-3 py-2 border rounded text-sm" type="number" min="0" value="${data.points || 1}" placeholder="Points" />
        </label>
        <label class="text-xs text-slate-600">Time limit (sec, 0 = none)
          <input class="q-time w-full mt-1 px-3 py-2 border rounded text-sm" type="number" min="0" step="5" value="${data.time_limit_seconds || 0}" placeholder="0" />
        </label>
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
      // === New interactive types ===
      if (t === 'matching' || t === 'drag_drop') {
        const labelLeft  = t === 'matching' ? 'Prompt' : 'Item';
        const labelRight = t === 'matching' ? 'Correct match' : 'Correct category / bin';
        const pairs = (data.options && data.options.length && typeof data.options[0] === 'object')
          ? data.options : [{a:'',b:''},{a:'',b:''}];
        const wrapHdr = document.createElement('p');
        wrapHdr.className = 'text-xs text-slate-500 mb-1';
        wrapHdr.textContent = (t === 'matching')
          ? 'Define pairs — students will see left items and pick the matching right item from a shuffled dropdown.'
          : 'Define items and the correct category each belongs to — students will pick a category for each item.';
        optsWrap.appendChild(wrapHdr);
        pairs.forEach(p => addPairRow(p.a || p.item || '', p.b || p.bin || '', t, labelLeft, labelRight));
        const addBtn = document.createElement('button');
        addBtn.textContent = '+ row'; addBtn.className = 'text-xs text-brand-700 hover:underline mt-1';
        addBtn.onclick = (e) => { e.preventDefault(); addPairRow('', '', typeSel.value, labelLeft, labelRight); };
        optsWrap.appendChild(addBtn);
        return;
      }
      if (t === 'ordering') {
        const hdr = document.createElement('p');
        hdr.className = 'text-xs text-slate-500 mb-1';
        hdr.textContent = 'List items in their correct order — they will be shuffled for the student.';
        optsWrap.appendChild(hdr);
        const items = (data.options || []).filter(x => typeof x === 'string');
        (items.length ? items : ['', '']).forEach(it => addOptionRow(it, false, 'ordering'));
        const addBtn = document.createElement('button');
        addBtn.textContent = '+ step'; addBtn.className = 'text-xs text-brand-700 hover:underline mt-1';
        addBtn.onclick = (e) => { e.preventDefault(); addOptionRow('', false, 'ordering'); };
        optsWrap.appendChild(addBtn);
        return;
      }
      if (t === 'hotspot') {
        const hdr = document.createElement('div');
        hdr.className = 'space-y-2';
        hdr.innerHTML = `
          <p class="text-xs text-slate-500">Paste an image URL (full http/https path), then add hot-spots — coordinates are 0–1 (e.g. center = 0.5, 0.5). Student must click within radius.</p>
          <label class="text-xs">Image URL <input class="nb-image-url w-full px-2 py-1 border rounded text-sm" value="${esc(data.image_url || '')}" placeholder="https://example.com/diagram.png" /></label>
        `;
        optsWrap.appendChild(hdr);
        const spots = (data.options && data.options.length && typeof data.options[0] === 'object')
          ? data.options : [{x: 0.5, y: 0.5, r: 0.08, label: 'spot'}];
        spots.forEach(s => addHotspotRow(s));
        const addBtn = document.createElement('button');
        addBtn.textContent = '+ hot-spot'; addBtn.className = 'text-xs text-brand-700 hover:underline mt-1';
        addBtn.onclick = (e) => { e.preventDefault(); addHotspotRow({x:0.5,y:0.5,r:0.08,label:''}); };
        optsWrap.appendChild(addBtn);
        return;
      }
      if (['mcq_single', 'mcq_multi', 'poll', 'dropdown'].includes(t)) {
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
      // Ordering / dropdown / poll don't need radio/checkbox markers
      const correctCtrl = (['ordering', 'poll', 'dropdown'].includes(currentType))
        ? '<span class="w-4"></span>'
        : `<input type="${currentType === 'mcq_multi' ? 'checkbox' : 'radio'}" name="opt-correct" ${isCorrect ? 'checked' : ''} />`;
      row.innerHTML = `
        ${correctCtrl}
        <input type="text" value="${esc(text)}" placeholder="${currentType === 'ordering' ? 'Step text…' : 'Option text…'}" class="flex-1 px-2 py-1.5 border rounded text-sm" />
        <button class="text-red-600 text-xs hover:underline">remove</button>
      `;
      row.querySelector('button').onclick = (e) => { e.preventDefault(); row.remove(); };
      optsWrap.appendChild(row);
    }

    function addPairRow(left, right, currentType, labelLeft, labelRight) {
      const row = document.createElement('div');
      row.className = 'flex items-center gap-2 mb-1';
      row.innerHTML = `
        <input type="text" value="${esc(left)}" placeholder="${labelLeft}" class="flex-1 px-2 py-1.5 border rounded text-sm pair-a" />
        <span class="text-slate-400">→</span>
        <input type="text" value="${esc(right)}" placeholder="${labelRight}" class="flex-1 px-2 py-1.5 border rounded text-sm pair-b" />
        <button class="text-red-600 text-xs hover:underline">remove</button>
      `;
      row.querySelector('button').onclick = (e) => { e.preventDefault(); row.remove(); };
      optsWrap.appendChild(row);
    }

    function addHotspotRow(spot) {
      const row = document.createElement('div');
      row.className = 'grid grid-cols-4 gap-1 mb-1 hotspot-row';
      row.innerHTML = `
        <input type="text" placeholder="Label" value="${esc(spot.label || '')}" class="px-2 py-1.5 border rounded text-sm hs-label" />
        <input type="number" min="0" max="1" step="0.01" placeholder="x (0–1)" value="${esc(spot.x)}" class="px-2 py-1.5 border rounded text-sm hs-x" />
        <input type="number" min="0" max="1" step="0.01" placeholder="y (0–1)" value="${esc(spot.y)}" class="px-2 py-1.5 border rounded text-sm hs-y" />
        <div class="flex gap-1">
          <input type="number" min="0.01" max="1" step="0.01" placeholder="radius" value="${esc(spot.r || 0.08)}" class="flex-1 px-2 py-1.5 border rounded text-sm hs-r" />
          <button class="text-red-600 text-xs hover:underline">×</button>
        </div>
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
        time_limit_seconds: parseInt(wrap.querySelector('.q-time').value || '0', 10),
        explanation: wrap.querySelector('.q-expl').value,
        options: [],
        correct_answers: [],
      };
      if (!payload.text) { alert('Question text is required.'); return; }
      const t = payload.type;
      if (t === 'matching' || t === 'drag_drop') {
        const pairs = [];
        wrap.querySelectorAll('.pair-a').forEach((leftInput, i) => {
          const a = leftInput.value.trim();
          const b = wrap.querySelectorAll('.pair-b')[i].value.trim();
          if (a && b) pairs.push(t === 'matching' ? {a, b} : {item: a, bin: b});
        });
        if (pairs.length < 2) { alert('At least 2 pairs required.'); return; }
        payload.options = pairs;
      } else if (t === 'ordering') {
        const items = [];
        wrap.querySelectorAll('.q-options-wrap input[type="text"]').forEach(inp => {
          const v = inp.value.trim();
          if (v) items.push(v);
        });
        if (items.length < 2) { alert('At least 2 items required.'); return; }
        payload.options = items;
        payload.correct_answers = items.map((_, i) => i);  // correct order = sequence as authored
      } else if (t === 'hotspot') {
        const url = wrap.querySelector('.nb-image-url');
        payload.image_url = (url && url.value || '').trim();
        if (!payload.image_url) { alert('Image URL is required for hotspot questions.'); return; }
        const spots = [];
        wrap.querySelectorAll('.hotspot-row').forEach(row => {
          spots.push({
            label: row.querySelector('.hs-label').value.trim(),
            x: parseFloat(row.querySelector('.hs-x').value || '0.5'),
            y: parseFloat(row.querySelector('.hs-y').value || '0.5'),
            r: parseFloat(row.querySelector('.hs-r').value || '0.08'),
          });
        });
        if (!spots.length) { alert('At least one hotspot is required.'); return; }
        payload.options = spots;
      } else if (['mcq_single', 'mcq_multi', 'poll', 'dropdown'].includes(t)) {
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
      // Loadless: replace the editor with a static card; keep teacher on the page
      payload.id = json.id || payload.id;
      const card = renderCard(payload);
      wrap.replaceWith(card);
      // Update the in-memory map so subsequent edits work
      window.QF_QUESTIONS_BY_ID = window.QF_QUESTIONS_BY_ID || {};
      window.QF_QUESTIONS_BY_ID[payload.id] = payload;
      if (emptyMsg) emptyMsg.classList.add('hidden');
      flashSaved('Question saved.');
    };
    return wrap;
  }

  function flashSaved(msg) {
    let bar = document.getElementById('inline-flash');
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'inline-flash';
      bar.className = 'fixed bottom-6 right-6 z-50 px-4 py-2 rounded-lg shadow-lg bg-emerald-600 text-white text-sm';
      document.body.appendChild(bar);
    }
    bar.textContent = msg;
    bar.style.display = 'block';
    clearTimeout(window._flashTimer);
    window._flashTimer = setTimeout(() => { bar.style.display = 'none'; }, 1800);
  }

  function renderCard(data) {
    const card = document.createElement('div');
    card.className = 'bg-white border border-slate-200 rounded-lg p-4 question-card fade-in';
    card.dataset.qid = data.id;
    const idx = document.querySelectorAll('.question-card').length;  // index after replacement
    const points = data.points || 1;
    const optsHtml = (data.options || []).map((opt, i) =>
      `<li class="flex items-start gap-2"><span class="inline-block w-5 text-slate-400">${'ABCDEFGH'[i]}.</span><span>${esc(opt)}</span>${(data.correct_answers || []).includes(i) ? '<span class="text-emerald-600 text-xs">✓ correct</span>' : ''}</li>`
    ).join('');
    card.innerHTML = `
      <div class="flex items-start justify-between gap-3">
        <div class="flex-1">
          <div class="text-xs text-slate-500 mb-1">
            Q${idx + 1} · <span class="capitalize">${(data.type || '').replace(/_/g, ' ')}</span> · ${points} pt${points !== 1 ? 's' : ''}
            ${data.time_limit_seconds ? ` · <span class="text-amber-700">⏱ ${data.time_limit_seconds}s</span>` : ''}
          </div>
          <div class="font-medium">${esc(data.text)}</div>
          ${optsHtml ? `<ul class="mt-2 text-sm text-slate-700 space-y-0.5">${optsHtml}</ul>` : ''}
          ${(data.correct_answers && data.correct_answers.length && !(data.options || []).length) ?
            `<div class="mt-2 text-sm text-emerald-700">Accepted: ${esc((data.correct_answers || []).join(' / '))}</div>` : ''}
          ${data.explanation ? `<div class="mt-2 text-xs text-slate-500 italic">${esc(data.explanation)}</div>` : ''}
        </div>
        <div class="flex flex-col gap-1">
          <button class="edit-q-btn text-xs px-2 py-1 bg-slate-100 rounded hover:bg-slate-200">Edit</button>
          <button class="delete-q-btn text-xs px-2 py-1 bg-red-50 text-red-700 rounded hover:bg-red-100">Delete</button>
        </div>
      </div>`;
    bindCardButtons(card);
    return card;
  }

  function bindCardButtons(card) {
    const editBtn = card.querySelector('.edit-q-btn');
    if (editBtn) editBtn.addEventListener('click', () => {
      const data = window.QF_QUESTIONS_BY_ID[card.dataset.qid];
      if (!data) { alert('Reload and try again.'); return; }
      const editor = buildEditor(data);
      card.replaceWith(editor);
    });
    const delBtn = card.querySelector('.delete-q-btn');
    if (delBtn) delBtn.addEventListener('click', async () => {
      if (!confirm('Delete this question?')) return;
      const qid = card.dataset.qid;
      await fetch(`/admin/quizzes/${quizId}/questions/${qid}/delete`, { method: 'POST' });
      delete window.QF_QUESTIONS_BY_ID[qid];
      card.remove();
      // Re-number remaining cards
      document.querySelectorAll('.question-card').forEach((c, i) => {
        const meta = c.querySelector('.text-xs.text-slate-500');
        if (meta && meta.textContent.startsWith('Q')) {
          meta.innerHTML = meta.innerHTML.replace(/^Q\d+/, 'Q' + (i + 1));
        }
      });
      if (document.querySelectorAll('.question-card').length === 0 && emptyMsg) {
        emptyMsg.classList.remove('hidden');
      }
      flashSaved('Question deleted.');
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  addBtn.onclick = () => {
    emptyMsg && emptyMsg.classList.add('hidden');
    list.appendChild(buildEditor(null));
  };

  // Bind initial server-rendered cards through the unified handler
  document.querySelectorAll('.question-card').forEach(bindCardButtons);
})();
