<?php
/**
 * Live sessions (Kahoot-style) — shared-hosting friendly via AJAX polling,
 * no WebSockets. State machine on live_sessions:
 *   status: 'waiting' (lobby) | 'running' (question active) | 'ended'
 *   current_question_index: -1 lobby, 0..N-1 active, >=N finished
 */

declare(strict_types=1);

/** Non-section questions for a quiz, ordered — the live "deck". */
function live_questions(int $quizId): array
{
    $qs = quiz_questions($quizId);
    return array_values(array_filter($qs, fn($q) => $q['type'] !== 'section_break'));
}

function live_get_session(int $sid): ?array
{
    return DB::one("SELECT * FROM live_sessions WHERE id=?", [$sid]);
}

/** Owner check for host routes. */
function live_owned_session(int $sid): array
{
    $s = live_get_session($sid);
    if (!$s) { http_response_code(404); page('error',['title'=>'Not found','code'=>404,'message'=>'Session not found.']); exit; }
    $q = DB::one("SELECT * FROM quizzes WHERE id=?", [$s['quiz_id']]);
    if (!$q || (int)$q['user_id'] !== (int)($_SESSION['uid'] ?? 0)) {
        http_response_code(404); page('error',['title'=>'Not found','code'=>404,'message'=>'Session not found.']); exit;
    }
    return $s;
}

/** Leaderboard: participants ordered by score desc. */
function live_leaderboard(int $sid, int $limit = 100): array
{
    return DB::all("SELECT id, name, score FROM live_participants WHERE session_id=? ORDER BY score DESC, joined_at ASC LIMIT $limit", [$sid]);
}

/** Answer-count distribution for the current question (for the host view). */
function live_answer_distribution(int $sid, int $qIndex, array $question): array
{
    $counts = array_fill(0, max(1, count($question['options'] ?? [])), 0);
    $rows = DB::all("SELECT answer FROM live_answers WHERE session_id=? AND q_index=?", [$sid, $qIndex]);
    foreach ($rows as $r) {
        $v = json_col($r['answer'], null);
        $i = to_int(is_array($v) ? ($v[0] ?? null) : $v, null);
        if ($i !== null && isset($counts[$i])) $counts[$i]++;
    }
    return $counts;
}

/** Advance to the next question (or end). Returns the new index. */
function live_advance(array $session): int
{
    $qs = live_questions((int)$session['quiz_id']);
    $next = (int)$session['current_question_index'] + 1;
    if ($next >= count($qs)) {
        DB::run("UPDATE live_sessions SET status='ended', current_question_index=?, ended_at=? WHERE id=?",
            [count($qs), now_ts(), $session['id']]);
        return count($qs);
    }
    DB::run("UPDATE live_sessions SET status='running', current_question_index=? WHERE id=?", [$next, $session['id']]);
    return $next;
}
