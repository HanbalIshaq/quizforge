<?php
/**
 * Quiz helpers: access guards + per-kind metadata (labels, colors, copy).
 * Org-aware access is layered in Step 6; for now access = ownership.
 */

declare(strict_types=1);

/** Kind metadata for dashboards + hubs. */
function kind_meta(): array
{
    return [
        'exam'   => ['label' => 'Exams & Quizzes', 'icon' => '📝', 'accent' => 'brand',
                     'desc' => 'Graded tests with pass marks, anti-cheating and certificates.'],
        'poll'   => ['label' => 'Polls', 'icon' => '📊', 'accent' => 'amber',
                     'desc' => 'Live opinion polls with charts, NPS and word clouds.'],
        'survey' => ['label' => 'Surveys', 'icon' => '📋', 'accent' => 'emerald',
                     'desc' => 'Anonymous feedback & research. Names not collected.'],
        'form'   => ['label' => 'Forms', 'icon' => '🗂️', 'accent' => 'purple',
                     'desc' => 'Collect data — registration, contact, applications.'],
    ];
}

/** Fetch a quiz the current user may access, or abort 404. */
function get_owned_quiz(int $quizId): array
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    $q = DB::one("SELECT * FROM quizzes WHERE id = ?", [$quizId]);
    if (!$q) { http_response_code(404); page('error', ['title'=>'Not found','code'=>404,'message'=>'Quiz not found.']); exit; }
    if ((int)$q['user_id'] !== $uid) {
        // Org access comes in Step 6; for now only the owner.
        http_response_code(404); page('error', ['title'=>'Not found','code'=>404,'message'=>'Quiz not found.']); exit;
    }
    return $q;
}

/** Counts of the user's quizzes by kind (for dashboard cards). */
function quiz_kind_counts(int $uid): array
{
    $out = ['all' => 0, 'exam' => 0, 'poll' => 0, 'survey' => 0, 'form' => 0];
    $rows = DB::all("SELECT kind, COUNT(*) AS c FROM quizzes WHERE user_id = ? GROUP BY kind", [$uid]);
    foreach ($rows as $r) {
        $out['all'] += (int)$r['c'];
        if (isset($out[$r['kind']])) $out[$r['kind']] = (int)$r['c'];
    }
    return $out;
}

/** Load a quiz's questions ordered by position, decoding JSON columns. */
function quiz_questions(int $quizId): array
{
    $rows = DB::all("SELECT * FROM questions WHERE quiz_id = ? ORDER BY position, id", [$quizId]);
    foreach ($rows as &$q) {
        $q['options'] = json_col($q['options'], []);
        $q['correct_answers'] = json_col($q['correct_answers'], []);
    }
    return $rows;
}

/** Insert defaults for a new quiz of the given kind, return its id + share code. */
function create_quiz(int $uid, string $kind, string $title): array
{
    $kind = in_array($kind, ['exam','poll','survey','form'], true) ? $kind : 'exam';
    $code = unique_code('quizzes', 'share_code', 7);
    $now = now_ts();
    // Sensible per-kind defaults
    $requireName  = $kind === 'survey' ? 0 : 1;
    $requireEmail = 0;
    $showCorrect  = $kind === 'exam' ? 1 : 0;
    $paginated    = $kind === 'form' ? 1 : 0;
    $id = DB::insert(
        "INSERT INTO quizzes(user_id, title, description, share_code, kind, created_at, updated_at,
                             require_name, require_email, show_correct_answers, paginated, is_published)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,1)",
        [$uid, $title, '', $code, $kind, $now, $now, $requireName, $requireEmail, $showCorrect, $paginated]
    );
    return ['id' => $id, 'share_code' => $code];
}
