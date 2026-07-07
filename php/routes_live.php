<?php
/**
 * Live sessions (Kahoot-style). Host controls the pace; participants join with
 * a code and answer on their own devices. State is polled over AJAX so it works
 * on plain shared hosting (no WebSockets required).
 *
 * Host routes live under /admin/live/* (login + ownership required).
 * Participant routes live under /live/* (public; CSRF-exempt by prefix).
 */

declare(strict_types=1);

/** Emit JSON and stop. */
function live_json($data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Short, unambiguous join code (no 0/O/1/I). */
function live_make_code(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 20; $try++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        if (!DB::scalar("SELECT 1 FROM live_sessions WHERE join_code=?", [$code])) return $code;
    }
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/** The participant identity for a session, from this browser's PHP session. */
function live_me(int $sid): ?array
{
    $tok = $_SESSION['live'][$sid]['token'] ?? null;
    if (!$tok) return null;
    return DB::one("SELECT * FROM live_participants WHERE session_id=? AND token=?", [$sid, $tok]);
}

/** Build the state payload a participant polls for. Hides correct answers. */
function live_participant_state(array $session, ?array $me): array
{
    $sid  = (int)$session['id'];
    $idx  = (int)$session['current_question_index'];
    $qs   = live_questions((int)$session['quiz_id']);
    $out  = [
        'status'      => $session['status'],
        'index'       => $idx,
        'total'       => count($qs),
        'reveal'      => (int)($session['reveal_results'] ?? 1),
        'you'         => $me ? ['name' => $me['name'], 'score' => (float)$me['score']] : null,
        'leaderboard' => array_map(fn($r) => ['name' => $r['name'], 'score' => (float)$r['score']], live_leaderboard($sid, 10)),
        'players'     => (int) DB::scalar("SELECT COUNT(*) FROM live_participants WHERE session_id=?", [$sid]),
    ];
    if ($session['status'] === 'running' && isset($qs[$idx])) {
        $q = $qs[$idx];
        $answered = $me ? (bool) DB::scalar(
            "SELECT 1 FROM live_answers WHERE session_id=? AND participant_id=? AND q_index=?",
            [$sid, $me['id'], $idx]) : false;
        $out['question'] = [
            'n'        => $idx + 1,
            'type'     => $q['type'],
            'prompt'   => $q['prompt'],
            'options'  => array_map(fn($o) => is_array($o) ? ($o['text'] ?? '') : $o, $q['options'] ?? []),
            'multi'    => $q['type'] === 'mcq_multi',
            'answered' => $answered,
        ];
    }
    return $out;
}

// ══════════════════════════════════════════════════════════════════════════
// HOST
// ══════════════════════════════════════════════════════════════════════════

/** Start (or resume) a live session for a quiz. */
route('POST', '/admin/quizzes/{id}/live', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    if (!live_questions((int)$quiz['id'])) {
        flash('Add at least one question before starting a live session.', 'error');
        redirect('/admin/quizzes/' . $quiz['id'] . '/edit');
    }
    // Reuse an existing non-ended session for this quiz if present.
    $open = DB::one("SELECT * FROM live_sessions WHERE quiz_id=? AND status<>'ended' ORDER BY id DESC LIMIT 1", [$quiz['id']]);
    if ($open) redirect('/admin/live/' . $open['id']);

    $code = live_make_code();
    $sid = DB::insert(
        "INSERT INTO live_sessions(quiz_id, join_code, status, current_question_index, started_at) VALUES(?,?,?,?,?)",
        [$quiz['id'], $code, 'waiting', -1, now_ts()]);
    redirect('/admin/live/' . $sid);
});

/** Host control room. */
route('GET', '/admin/live/{sid}', function ($p) {
    require_login();
    $s = live_owned_session((int)$p['sid']);
    $quiz = DB::one("SELECT * FROM quizzes WHERE id=?", [$s['quiz_id']]);
    page('live_host', [
        'title'   => 'Live · ' . $quiz['title'],
        'session' => $s,
        'quiz'    => $quiz,
        'joinUrl' => abs_url('/live/' . $s['join_code']),
        'total'   => count(live_questions((int)$s['quiz_id'])),
    ]);
});

/** Advance to the next question (or finish). */
route('POST', '/admin/live/{sid}/next', function ($p) {
    require_login();
    $s = live_owned_session((int)$p['sid']);
    $idx = live_advance($s);
    live_json(['ok' => true, 'index' => $idx]);
});

/** End the session now. */
route('POST', '/admin/live/{sid}/end', function ($p) {
    require_login();
    $s = live_owned_session((int)$p['sid']);
    $qs = live_questions((int)$s['quiz_id']);
    DB::run("UPDATE live_sessions SET status='ended', current_question_index=?, ended_at=? WHERE id=?",
        [count($qs), now_ts(), $s['id']]);
    live_json(['ok' => true]);
});

/** Host poll: participants, live answer distribution, leaderboard. */
route('GET', '/admin/live/{sid}/host.json', function ($p) {
    require_login();
    $s = live_owned_session((int)$p['sid']);
    $sid = (int)$s['id'];
    $idx = (int)$s['current_question_index'];
    $qs = live_questions((int)$s['quiz_id']);
    $payload = [
        'status'      => $s['status'],
        'index'       => $idx,
        'total'       => count($qs),
        'reveal'      => (int)$s['reveal_results'],
        'players'     => (int) DB::scalar("SELECT COUNT(*) FROM live_participants WHERE session_id=?", [$sid]),
        'roster'      => array_map(fn($r) => $r['name'],
            DB::all("SELECT name FROM live_participants WHERE session_id=? ORDER BY joined_at DESC LIMIT 60", [$sid])),
        'leaderboard' => array_map(fn($r) => ['name' => $r['name'], 'score' => (float)$r['score']], live_leaderboard($sid, 20)),
    ];
    if ($s['status'] === 'running' && isset($qs[$idx])) {
        $q = $qs[$idx];
        $payload['question'] = [
            'n'       => $idx + 1,
            'prompt'  => $q['prompt'],
            'options' => array_map(fn($o) => is_array($o) ? ($o['text'] ?? '') : $o, $q['options'] ?? []),
            'correct' => int_list($q['correct_answers'] ?? []),
        ];
        $payload['answered'] = (int) DB::scalar(
            "SELECT COUNT(DISTINCT participant_id) FROM live_answers WHERE session_id=? AND q_index=?", [$sid, $idx]);
        $payload['distribution'] = live_answer_distribution($sid, $idx, $q);
    }
    live_json($payload);
});

/** Toggle whether players see right/wrong (+ their points) during play. */
route('POST', '/admin/live/{sid}/reveal', function ($p) {
    require_login();
    $s = live_owned_session((int)$p['sid']);
    $on = (int)!empty($_POST['on']);
    DB::run("UPDATE live_sessions SET reveal_results=? WHERE id=?", [$on, $s['id']]);
    live_json(['ok' => true, 'reveal' => $on]);
});

// ══════════════════════════════════════════════════════════════════════════
// PARTICIPANT
// ══════════════════════════════════════════════════════════════════════════

/** Landing: enter a join code. */
route('GET', '/live', function () {
    page('live_join', ['title' => 'Join a live session', 'session' => null]);
});

/** Show the name form for a specific code. */
route('GET', '/live/{code}', function ($p) {
    $code = strtoupper(trim($p['code']));
    $s = DB::one("SELECT * FROM live_sessions WHERE join_code=?", [$code]);
    if (!$s || $s['status'] === 'ended') {
        page('live_join', ['title' => 'Join a live session', 'session' => null,
            'error' => $s ? 'That session has ended.' : 'No live session with that code.']);
        return;
    }
    // Already joined on this device? Jump straight to play.
    if (live_me((int)$s['id'])) redirect('/live/play/' . $s['id']);
    page('live_join', ['title' => 'Join · ' . $code, 'session' => $s, 'code' => $code]);
});

/** Register a participant. */
route('POST', '/live/{code}/join', function ($p) {
    $code = strtoupper(trim($p['code']));
    $s = DB::one("SELECT * FROM live_sessions WHERE join_code=?", [$code]);
    if (!$s || $s['status'] === 'ended') { redirect('/live'); }
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        page('live_join', ['title' => 'Join · ' . $code, 'session' => $s, 'code' => $code,
            'error' => 'Please enter a name.']);
        return;
    }
    $name = mb_substr($name, 0, 40);
    $token = bin2hex(random_bytes(16));
    $pid = DB::insert(
        "INSERT INTO live_participants(session_id, name, token, score, joined_at, last_seen) VALUES(?,?,?,?,?,?)",
        [$s['id'], $name, $token, 0, now_ts(), now_ts()]);
    $_SESSION['live'][(int)$s['id']] = ['token' => $token, 'pid' => $pid];
    redirect('/live/play/' . $s['id']);
});

/** Player screen. */
route('GET', '/live/play/{sid}', function ($p) {
    $sid = (int)$p['sid'];
    $s = live_get_session($sid);
    if (!$s) redirect('/live');
    $me = live_me($sid);
    if (!$me) redirect('/live/' . $s['join_code']);
    page('live_play', ['title' => 'Live', 'session' => $s, 'me' => $me, 'bare' => true]);
});

/** Player poll. */
route('GET', '/live/play/{sid}/state.json', function ($p) {
    $sid = (int)$p['sid'];
    $s = live_get_session($sid);
    if (!$s) live_json(['status' => 'gone']);
    $me = live_me($sid);
    if ($me) DB::run("UPDATE live_participants SET last_seen=? WHERE id=?", [now_ts(), $me['id']]);
    live_json(live_participant_state($s, $me));
});

/** Submit an answer for the current question. */
route('POST', '/live/play/{sid}/answer', function ($p) {
    $sid = (int)$p['sid'];
    $s = live_get_session($sid);
    if (!$s || $s['status'] !== 'running') live_json(['ok' => false, 'reason' => 'not_running']);
    $me = live_me($sid);
    if (!$me) live_json(['ok' => false, 'reason' => 'not_joined']);

    $idx = (int)$s['current_question_index'];
    $qs = live_questions((int)$s['quiz_id']);
    if (!isset($qs[$idx])) live_json(['ok' => false, 'reason' => 'no_question']);
    $q = $qs[$idx];

    // One answer per question per participant.
    if (DB::scalar("SELECT 1 FROM live_answers WHERE session_id=? AND participant_id=? AND q_index=?",
        [$sid, $me['id'], $idx])) {
        live_json(['ok' => false, 'reason' => 'already']);
    }

    $raw = $_POST['answer'] ?? null;
    if (is_string($raw) && ($decoded = json_decode($raw, true)) !== null) $raw = $decoded;
    [$isCorrect, $pts, $manual] = grade_answer($q, $raw);
    // Score with the question's real assigned points (incl. partial credit),
    // not an arbitrary flat number.
    $award = (float) $pts;

    DB::insert(
        "INSERT INTO live_answers(session_id, participant_id, q_index, question_id, answer, is_correct, points, created_at)
         VALUES(?,?,?,?,?,?,?,?)",
        [$sid, $me['id'], $idx, $q['id'], json_encode($raw), $isCorrect === true ? 1 : 0, $award, now_ts()]);
    if ($award != 0) DB::run("UPDATE live_participants SET score=score+? WHERE id=?", [$award, $me['id']]);

    // Only tell the player right/wrong when the host has reveal turned on.
    live_json(['ok' => true, 'reveal' => (int)$s['reveal_results'],
        'correct' => $isCorrect === true, 'award' => $award]);
});
