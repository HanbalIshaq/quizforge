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

/**
 * Render the input(s) for one question on the student take page.
 * $q is a decoded question row; $n is the field name base (q_<id>).
 * Returns an HTML string. Covers the common + form field types; interactive
 * types fall back to a sensible input.
 */
function render_take_question(array $q): string
{
    $id = (int)$q['id'];
    $name = "q_{$id}";
    $type = $q['type'];
    $opts = is_array($q['options']) ? $q['options'] : json_col($q['options'], []);
    $req = !empty($q['is_required']);
    $reqAttr = $req ? 'data-required="1"' : '';
    ob_start();

    if (in_array($type, ['mcq_single','true_false'], true)) {
        echo '<div class="space-y-2" '.$reqAttr.'>';
        foreach ($opts as $i => $opt) {
            echo '<label class="flex items-start gap-2 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">'
               . '<input type="radio" name="'.e($name).'" value="'.$i.'" class="mt-0.5" />'
               . '<span>'.e($opt).'</span></label>';
        }
        echo '</div>';
    } elseif ($type === 'mcq_multi') {
        echo '<div class="space-y-2" '.$reqAttr.'>';
        foreach ($opts as $i => $opt) {
            echo '<label class="flex items-start gap-2 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">'
               . '<input type="checkbox" name="'.e($name).'[]" value="'.$i.'" class="mt-0.5" />'
               . '<span>'.e($opt).'</span></label>';
        }
        echo '</div>';
    } elseif (in_array($type, ['dropdown','poll'], true)) {
        if ($type === 'poll') {
            echo '<div class="space-y-2" '.$reqAttr.'>';
            foreach ($opts as $i => $opt) {
                echo '<label class="flex items-start gap-2 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">'
                   . '<input type="radio" name="'.e($name).'" value="'.$i.'" class="mt-0.5" /><span>'.e($opt).'</span></label>';
            }
            echo '</div>';
        } else {
            echo '<select class="qf-select" name="'.e($name).'" '.$reqAttr.'><option value="">— Select —</option>';
            foreach ($opts as $i => $opt) echo '<option value="'.$i.'">'.e($opt).'</option>';
            echo '</select>';
        }
    } elseif (in_array($type, ['short_answer','fill_blank','email','phone','url','full_name'], true)) {
        $it = $type==='email'?'email':($type==='phone'?'tel':($type==='url'?'url':'text'));
        echo '<input type="'.$it.'" class="qf-input" name="'.e($name).'" '.$reqAttr.' />';
    } elseif ($type === 'number') {
        echo '<input type="number" class="qf-input" name="'.e($name).'" '.$reqAttr.' />';
    } elseif ($type === 'date') {
        echo '<input type="date" class="qf-input" name="'.e($name).'" '.$reqAttr.' />';
    } elseif ($type === 'time') {
        echo '<input type="time" class="qf-input" name="'.e($name).'" '.$reqAttr.' />';
    } elseif ($type === 'datetime') {
        echo '<input type="datetime-local" class="qf-input" name="'.e($name).'" '.$reqAttr.' />';
    } elseif (in_array($type, ['long_answer','open_ended','address'], true)) {
        echo '<textarea class="qf-textarea" name="'.e($name).'" rows="4" '.$reqAttr.'></textarea>';
    } elseif ($type === 'rating') {
        echo '<div class="flex gap-1" '.$reqAttr.'>';
        for ($i=1;$i<=5;$i++) echo '<label class="cursor-pointer"><input type="radio" class="sr-only qf-star" name="'.e($name).'" value="'.$i.'" /><span class="text-3xl text-slate-300" data-star="'.$i.'">★</span></label>';
        echo '</div>';
    } elseif ($type === 'nps') {
        echo '<div class="flex flex-wrap gap-1" '.$reqAttr.'>';
        for ($i=0;$i<=10;$i++) echo '<label class="cursor-pointer"><input type="radio" class="sr-only qf-nps" name="'.e($name).'" value="'.$i.'" /><span class="inline-grid place-items-center w-9 h-9 rounded-lg border border-slate-300 text-sm" data-nps="'.$i.'">'.$i.'</span></label>';
        echo '</div>';
    } elseif ($type === 'slider') {
        echo '<input type="range" min="0" max="100" value="50" class="w-full" name="'.e($name).'" oninput="this.nextElementSibling.textContent=this.value" /><output class="text-sm text-slate-600">50</output>';
    } elseif ($type === 'consent') {
        echo '<label class="flex items-start gap-2"><input type="checkbox" name="'.e($name).'" value="1" '.$reqAttr.' /><span class="text-sm">'.e($opts[0] ?? 'I agree to the terms.').'</span></label>';
    } elseif ($type === 'file_upload') {
        echo '<input type="file" name="'.e($name).'" class="text-sm" '.$reqAttr.' /><p class="qf-hint">Max 16 MB.</p>';
    } elseif ($type === 'signature') {
        echo '<input type="text" class="qf-input" name="'.e($name).'" placeholder="Type your full name to sign" '.$reqAttr.' />';
    } elseif ($type === 'section_break') {
        echo ''; // no input — the text is the content
    } else {
        // matching / ordering / drag_drop / hotspot: simple text fallback for now
        echo '<input type="text" class="qf-input" name="'.e($name).'" placeholder="Your answer" '.$reqAttr.' />';
    }
    return (string) ob_get_clean();
}

/**
 * Parse a submitted raw value for a question type into the normalized form
 * grade_answer() expects.
 */
function parse_submitted_answer(string $type, $raw)
{
    if ($raw === null) return null;
    if (in_array($type, ['mcq_single','true_false','dropdown','rating','nps'], true)) {
        if (is_array($raw)) $raw = $raw[0] ?? null;
        return ($raw === '' || $raw === null) ? null : to_int($raw, $raw);
    }
    if ($type === 'mcq_multi') {
        $raw = is_array($raw) ? $raw : [$raw];
        return int_list($raw);
    }
    if ($type === 'number' || $type === 'slider') {
        return ($raw === '') ? null : (is_numeric($raw) ? $raw + 0 : $raw);
    }
    // text-ish + everything else: trimmed string
    if (is_array($raw)) $raw = implode(', ', $raw);
    $s = trim((string)$raw);
    return $s === '' ? null : $s;
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
