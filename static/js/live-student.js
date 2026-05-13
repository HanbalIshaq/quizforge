// Student client for live sessions.
(function () {
  const cfg = window.LIVE_STUDENT_CONFIG;
  const socket = io({ transports: ['websocket', 'polling'] });

  const nameGate = document.getElementById('name-gate');
  const nameInput = document.getElementById('name-input');
  const nameBtn = document.getElementById('name-btn');
  const waiting = document.getElementById('waiting');
  const waitingName = document.getElementById('waiting-name');
  const qPanel = document.getElementById('question-panel');
  const qMeta = document.getElementById('q-meta');
  const qText = document.getElementById('q-text');
  const qOptions = document.getElementById('q-options');
  const answeredMsg = document.getElementById('answered-msg');
  const revealPanel = document.getElementById('reveal-panel');
  const revealContent = document.getElementById('reveal-content');
  const myResult = document.getElementById('my-result');
  const ended = document.getElementById('ended');
  const finalLb = document.getElementById('final-leaderboard');

  let myName = '';
  let currentQuestion = null;
  let answered = false;
  let mySessionId = null;

  function show(el) { [nameGate, waiting, qPanel, ended].forEach(e => e.classList.add('hidden')); el.classList.remove('hidden'); }

  nameBtn.onclick = () => {
    const n = nameInput.value.trim();
    if (!n) return;
    myName = n;
    socket.emit('join_student', { join_code: cfg.joinCode, name: n });
  };
  nameInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') nameBtn.click(); });

  socket.on('student_joined', (data) => {
    if (data && data.session_id) mySessionId = data.session_id;
    waitingName.textContent = 'You\'re in as ' + myName;
    show(waiting);
  });

  socket.on('show_question', (data) => {
    currentQuestion = data.question;
    answered = false;
    revealPanel.classList.add('hidden');
    answeredMsg.classList.add('hidden');
    qMeta.textContent = `Question ${data.index + 1} of ${data.total} · ${currentQuestion.points} pt`;
    qText.textContent = currentQuestion.text;
    qOptions.innerHTML = '';
    show(qPanel);
    renderAnswerUI(currentQuestion);
  });

  function renderAnswerUI(q) {
    const t = q.type;
    if (t === 'mcq_single' || t === 'poll' || t === 'true_false') {
      const opts = (t === 'true_false') ? ['True', 'False'] : q.options;
      opts.forEach((opt, i) => {
        const b = document.createElement('button');
        b.className = 'w-full text-left p-3 border-2 rounded hover:border-brand-500 hover:bg-brand-50 transition';
        b.innerHTML = `<span class="font-mono text-slate-500 mr-2">${'ABCDEFGH'[i]}.</span>${escapeHtml(opt)}`;
        b.onclick = () => submit(i, b);
        qOptions.appendChild(b);
      });
    } else if (t === 'mcq_multi') {
      const sel = new Set();
      q.options.forEach((opt, i) => {
        const lbl = document.createElement('label');
        lbl.className = 'flex items-center gap-2 p-3 border-2 rounded cursor-pointer hover:border-brand-500';
        lbl.innerHTML = `<input type="checkbox" value="${i}"/> <span><span class="font-mono text-slate-500 mr-1">${'ABCDEFGH'[i]}.</span>${escapeHtml(opt)}</span>`;
        lbl.querySelector('input').onchange = (e) => {
          if (e.target.checked) sel.add(i); else sel.delete(i);
        };
        qOptions.appendChild(lbl);
      });
      const btn = document.createElement('button');
      btn.className = 'w-full py-3 bg-brand-600 text-white rounded hover:bg-brand-700';
      btn.textContent = 'Submit';
      btn.onclick = () => submit(Array.from(sel), btn);
      qOptions.appendChild(btn);
    } else if (t === 'rating') {
      [1,2,3,4,5].forEach(n => {
        const b = document.createElement('button');
        b.className = 'flex-1 py-4 text-xl border-2 rounded hover:border-amber-500 hover:bg-amber-50';
        b.textContent = `${n} ★`;
        b.onclick = () => submit(n, b);
        qOptions.classList.add('flex','gap-2');
        qOptions.appendChild(b);
      });
    } else {
      const ta = document.createElement('textarea');
      ta.rows = 3; ta.className = 'w-full px-3 py-2 border-2 rounded';
      ta.placeholder = 'Type your answer...';
      const btn = document.createElement('button');
      btn.className = 'mt-2 w-full py-3 bg-brand-600 text-white rounded hover:bg-brand-700';
      btn.textContent = 'Submit';
      btn.onclick = () => submit(ta.value.trim(), btn);
      qOptions.appendChild(ta);
      qOptions.appendChild(btn);
    }
  }

  function submit(value, btn) {
    if (answered || !currentQuestion) return;
    answered = true;
    socket.emit('student_answer', {
      session_id: mySessionId,
      question_id: currentQuestion.id,
      answer: value,
    });
    // disable UI
    Array.from(qOptions.querySelectorAll('button,input,textarea')).forEach(el => el.disabled = true);
    if (btn) btn.classList.add('bg-emerald-600');
    answeredMsg.classList.remove('hidden');
  }

  socket.on('answer_received', () => { /* could play a sound */ });

  socket.on('reveal_answer', (data) => {
    revealPanel.classList.remove('hidden');
    revealContent.innerHTML = '';
    const opts = data.options || [];
    if (opts.length) {
      opts.forEach((opt, i) => {
        const n = (data.aggregate.counts && data.aggregate.counts[i]) || 0;
        const total = data.aggregate.total || 1;
        const pct = (n / total * 100) || 0;
        const isCorrect = (data.correct_answers || []).includes(i);
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2';
        row.innerHTML = `
          <span class="w-7 font-mono text-slate-500">${'ABCDEFGH'[i]}.</span>
          <span class="flex-1 text-sm">${escapeHtml(opt)} ${isCorrect ? '<span class="text-emerald-600">&check;</span>' : ''}</span>
          <div class="w-32 bg-slate-100 h-2 rounded overflow-hidden"><div class="h-2 ${isCorrect ? 'bg-emerald-500' : 'bg-slate-400'}" style="width:${pct}%"></div></div>
          <span class="text-xs text-slate-500 w-10 text-right">${n}</span>`;
        revealContent.appendChild(row);
      });
    } else if (data.aggregate.texts) {
      revealContent.innerHTML = data.aggregate.texts.map(t =>
        `<span class="inline-block px-2 py-1 bg-brand-50 border border-brand-200 text-brand-800 rounded text-sm mr-1 mb-1">${escapeHtml(t)}</span>`
      ).join('');
    }
  });

  socket.on('leaderboard', () => { /* shown at end only */ });

  socket.on('session_ended', (data) => {
    show(ended);
    if (data.leaderboard) {
      finalLb.innerHTML = data.leaderboard.map((p, i) =>
        `<li class="flex justify-between ${p.name === myName ? 'bg-brand-50 font-semibold' : 'bg-slate-50'} px-2 py-1 rounded">
           <span>${i+1}. ${escapeHtml(p.name)}</span><span class="font-mono">${p.score} pt</span>
         </li>`
      ).join('');
    }
  });

  socket.on('error_msg', (data) => alert(data.msg || 'Error'));

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
})();
