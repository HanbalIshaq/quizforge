// Host control panel for live sessions.
(function () {
  const cfg = window.LIVE_CONFIG;
  const socket = io({ transports: ['websocket', 'polling'] });
  socket.on('connect', () => socket.emit('join_host', { session_id: cfg.sessionId }));

  const participantList = document.getElementById('participant-list');
  const participantCount = document.getElementById('participant-count');
  const statusMsg = document.getElementById('status-msg');
  const currentBox = document.getElementById('current-question');
  const qMeta = document.getElementById('q-meta');
  const qText = document.getElementById('q-text');
  const qOptions = document.getElementById('q-options');
  const statsContainer = document.getElementById('stats-container');
  const answerCount = document.getElementById('answer-count');
  const answerProgress = document.getElementById('answer-progress');
  const leaderboard = document.getElementById('leaderboard');

  let currentQuestion = null;
  let participantTotal = 0;

  function renderParticipants(list) {
    participantTotal = list.length;
    participantCount.textContent = list.length;
    participantList.innerHTML = list.map(p =>
      `<li class="flex justify-between bg-slate-50 px-2 py-1 rounded"><span>${escapeHtml(p.name)}</span><span class="text-xs text-slate-500">${p.score || 0} pt</span></li>`
    ).join('');
  }

  function showQuestion(q, idx, total) {
    currentQuestion = q;
    statusMsg.classList.add('hidden');
    currentBox.classList.remove('hidden');
    qMeta.textContent = `Question ${idx + 1} of ${total} · ${q.type.replace('_',' ')} · ${q.points} pt`;
    qText.textContent = q.text;
    qOptions.innerHTML = '';
    statsContainer.innerHTML = '';
    answerCount.textContent = '0';
    answerProgress.textContent = participantTotal ? `0 / ${participantTotal} answered` : '';
    if (q.options && q.options.length) {
      q.options.forEach((opt, i) => {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-3 p-3 border rounded';
        row.innerHTML = `<span class="w-7 font-mono text-slate-500">${'ABCDEFGH'[i]}.</span><span class="flex-1">${escapeHtml(opt)}</span>`;
        qOptions.appendChild(row);
      });
    } else {
      qOptions.innerHTML = '<p class="text-sm text-slate-500">Open-ended &mdash; students will type a response.</p>';
    }
    renderStats({ counts: {}, texts: [], total: 0 }, q);
  }

  function renderStats(agg, q, revealed) {
    statsContainer.innerHTML = '';
    answerCount.textContent = agg.total || 0;
    answerProgress.textContent = participantTotal ? `${agg.total || 0} / ${participantTotal} answered` : '';
    if (q.options && q.options.length) {
      q.options.forEach((opt, i) => {
        const n = (agg.counts && agg.counts[i]) || 0;
        const pct = agg.total ? (n / agg.total * 100) : 0;
        const isCorrect = revealed && revealed.correct_answers && revealed.correct_answers.includes(i);
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2';
        row.innerHTML = `
          <span class="w-7 font-mono text-slate-500">${'ABCDEFGH'[i]}.</span>
          <span class="flex-1 text-sm">${escapeHtml(opt)} ${isCorrect ? '<span class="text-emerald-600">&check;</span>' : ''}</span>
          <div class="w-48 bg-slate-100 h-3 rounded overflow-hidden">
            <div class="h-3 bar-grow ${isCorrect ? 'bg-emerald-500' : 'bg-brand-500'}" style="width:${pct}%"></div>
          </div>
          <span class="text-xs text-slate-500 w-16 text-right">${n} (${pct.toFixed(0)}%)</span>`;
        statsContainer.appendChild(row);
      });
    } else if (agg.texts && agg.texts.length) {
      const wrap = document.createElement('div');
      wrap.className = 'flex flex-wrap gap-2';
      agg.texts.forEach(t => {
        const tag = document.createElement('span');
        tag.className = 'px-2 py-1 bg-brand-50 border border-brand-200 text-brand-800 rounded text-sm';
        tag.textContent = t;
        wrap.appendChild(tag);
      });
      statsContainer.appendChild(wrap);
    }
  }

  function renderLeaderboard(items) {
    leaderboard.innerHTML = items.map((p, i) => `
      <li class="flex justify-between px-2 py-1 ${i === 0 ? 'bg-amber-50' : 'bg-slate-50'} rounded">
        <span>${i+1}. ${escapeHtml(p.name)}</span><span class="font-mono text-slate-600">${p.score} pt</span>
      </li>`).join('');
  }

  socket.on('host_state', (data) => {
    if (data.participants) renderParticipants(data.participants);
  });
  socket.on('participants_update', (data) => renderParticipants(data.participants));
  socket.on('show_question', (data) => showQuestion(data.question, data.index, data.total));
  socket.on('answer_stats', (data) => {
    if (currentQuestion && currentQuestion.id === data.question_id) {
      renderStats(data.aggregate, currentQuestion);
    }
  });
  socket.on('reveal_answer', (data) => {
    if (currentQuestion && currentQuestion.id === data.question_id) {
      renderStats(data.aggregate, currentQuestion, data);
    }
  });
  socket.on('leaderboard', (data) => renderLeaderboard(data.leaderboard));
  socket.on('session_ended', (data) => {
    statusMsg.classList.remove('hidden');
    currentBox.classList.add('hidden');
    statusMsg.innerHTML = '<b>Session ended.</b> Results saved.';
    if (data.leaderboard) renderLeaderboard(data.leaderboard);
  });

  document.getElementById('btn-next').onclick = () => socket.emit('host_next', { session_id: cfg.sessionId });
  document.getElementById('btn-reveal').onclick = () => socket.emit('host_reveal', { session_id: cfg.sessionId });
  document.getElementById('btn-end').onclick = () => {
    if (confirm('End this live session?')) socket.emit('host_end', { session_id: cfg.sessionId });
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
})();
