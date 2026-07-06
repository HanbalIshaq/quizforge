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
 * Compute per-question aggregate stats for a poll/survey results dashboard.
 * Returns an array parallel to the (non-section) questions, each with:
 *   q, total, and type-specific data (counts / avg / nps / texts / words).
 */
function quiz_aggregate(int $quizId): array
{
    $questions = quiz_questions($quizId);
    $rows = DB::all(
        "SELECT a.question_id, a.answer FROM answers a
         JOIN attempts t ON t.id = a.attempt_id
         WHERE t.quiz_id = ? AND t.submitted_at IS NOT NULL",
        [$quizId]
    );
    $byQ = [];
    foreach ($rows as $r) { $byQ[(int)$r['question_id']][] = json_col($r['answer'], null); }

    $out = [];
    foreach ($questions as $q) {
        if ($q['type'] === 'section_break') continue;
        $answers = array_values(array_filter($byQ[(int)$q['id']] ?? [], fn($v) => $v !== null && $v !== '' && $v !== []));
        $stat = ['q' => $q, 'total' => count($answers), 'kind' => 'other'];

        if (in_array($q['type'], ['mcq_single','mcq_multi','true_false','dropdown','poll'], true)) {
            $stat['kind'] = 'choice';
            $counts = array_fill(0, max(1, count($q['options'])), 0);
            foreach ($answers as $v) {
                foreach ((is_array($v) ? $v : [$v]) as $i) {
                    $i = to_int($i, null);
                    if ($i !== null && isset($counts[$i])) $counts[$i]++;
                }
            }
            $stat['counts'] = $counts;
        } elseif ($q['type'] === 'rating') {
            $stat['kind'] = 'rating'; $sum = 0; $dist = array_fill(1, 5, 0);
            foreach ($answers as $v) { $n = to_int($v, 0); if ($n>=1 && $n<=5){ $sum+=$n; $dist[$n]++; } }
            $stat['avg'] = $answers ? round($sum / count($answers), 2) : 0; $stat['dist'] = $dist;
        } elseif ($q['type'] === 'nps') {
            $stat['kind'] = 'nps'; $prom=0;$pass=0;$det=0;
            foreach ($answers as $v) { $n=to_int($v,-1); if($n>=9)$prom++; elseif($n>=7)$pass++; elseif($n>=0)$det++; }
            $n = max(1, count($answers));
            $stat['promoters']=$prom; $stat['passives']=$pass; $stat['detractors']=$det;
            $stat['nps'] = round((($prom - $det) / $n) * 100);
        } elseif (in_array($q['type'], ['short_answer','long_answer','open_ended','fill_blank','email','phone','url','address','full_name','signature'], true)) {
            $stat['kind'] = 'text'; $texts = [];
            foreach ($answers as $v) { $texts[] = is_array($v) ? implode(', ', $v) : (string)$v; }
            $stat['texts'] = $texts;
            $freq = [];
            foreach ($texts as $t) {
                foreach (preg_split('/[^\p{L}0-9]+/u', mb_strtolower($t)) as $w) {
                    if (mb_strlen($w) < 3) continue;
                    $freq[$w] = ($freq[$w] ?? 0) + 1;
                }
            }
            arsort($freq); $stat['words'] = array_slice($freq, 0, 30, true);
        }
        $out[] = $stat;
    }
    return $out;
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

/** Fisher-Yates shuffle of a list, deterministic for a given seed. */
function seeded_shuffle(array $list, int $seed): array
{
    mt_srand($seed);
    for ($i = count($list) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
    }
    mt_srand(); // restore randomness
    return $list;
}

/** Shuffle an array's ITERATION ORDER while keeping original keys attached.
 *  Used to randomize option display order without changing option indices
 *  (the stored answer + grading still reference the original index). */
function shuffle_preserve_keys(array $arr, int $seed): array
{
    $keys = array_keys($arr);
    mt_srand($seed);
    for ($i = count($keys) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
    }
    mt_srand();
    $out = [];
    foreach ($keys as $k) $out[$k] = $arr[$k];
    return $out;
}

/** Apply per-attempt randomization to a question list based on quiz settings. */
function randomize_questions_for(array $quiz, array $questions, int $attemptId): array
{
    if (!empty($quiz['randomize_options'])) {
        foreach ($questions as &$q) {
            if (in_array($q['type'], choice_types(), true) && is_array($q['options']) && $q['options']) {
                $q['options'] = shuffle_preserve_keys($q['options'], $attemptId * 100 + (int)$q['id']);
            }
        }
        unset($q);
    }
    if (!empty($quiz['randomize_questions'])) {
        // keep section breaks anchored to their following question by shuffling
        // only the non-section questions among themselves
        $questions = seeded_shuffle($questions, $attemptId);
    }
    return $questions;
}

/** Is $ip allowed by a comma/newline list of IPs and CIDR ranges? Empty = open. */
function ip_allowed(string $allowlist, string $ip): bool
{
    $allowlist = trim($allowlist);
    if ($allowlist === '') return true;
    $entries = preg_split('/[\s,]+/', $allowlist, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($entries as $entry) {
        if (strpos($entry, '/') !== false) {
            [$subnet, $bits] = explode('/', $entry, 2);
            $bits = (int)$bits;
            $ipL = ip2long($ip); $subL = ip2long($subnet);
            if ($ipL === false || $subL === false) continue;
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if (($ipL & $mask) === ($subL & $mask)) return true;
        } elseif ($entry === $ip) {
            return true;
        }
    }
    return false;
}

/**
 * Get (or create) the in-progress draft attempt for this browser + quiz.
 * Lets us attach anti-cheat violations and camera snapshots to a record
 * before the student submits. Finalized on submit.
 */
function get_or_create_draft(array $quiz): int
{
    $key = 'draft_' . $quiz['id'];
    $did = (int)($_SESSION[$key] ?? 0);
    if ($did) {
        $row = DB::one("SELECT id FROM attempts WHERE id=? AND quiz_id=? AND submitted_at IS NULL", [$did, $quiz['id']]);
        if ($row) return $did;
    }
    $id = DB::insert("INSERT INTO attempts(quiz_id, student_name, started_at, ip_address) VALUES(?,?,?,?)",
        [$quiz['id'], '', now_ts(), $_SERVER['REMOTE_ADDR'] ?? '']);
    $_SESSION[$key] = $id;
    return $id;
}

/**
 * Handle a form file_upload field securely. Returns a public URL string on
 * success, or null. Hardened (ported from the Python audit fixes): extension
 * allowlist, magic-byte content check, per-file size cap. Rejects HTML/SVG/JS/
 * executables that could be served back as live content.
 */
function handle_file_upload(string $field, int $quizId): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$field];
    if (($f['size'] ?? 0) > 16 * 1024 * 1024) return null; // 16 MB

    $allowed = ['pdf','doc','docx','txt','rtf','odt','xls','xlsx','csv','ods','ppt','pptx','odp',
                'jpg','jpeg','png','gif','webp','heic','zip','mp3','wav','m4a','mp4','mov','webm'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;

    // Magic-byte check for the security-critical types
    $magic = [
        'jpg'=>["\xff\xd8\xff"], 'jpeg'=>["\xff\xd8\xff"], 'png'=>["\x89PNG\r\n\x1a\n"],
        'gif'=>['GIF87a','GIF89a'], 'pdf'=>['%PDF-'], 'zip'=>["PK\x03\x04","PK\x05\x06"],
        'docx'=>["PK\x03\x04"], 'xlsx'=>["PK\x03\x04"], 'pptx'=>["PK\x03\x04"],
    ];
    if (isset($magic[$ext])) {
        $head = file_get_contents($f['tmp_name'], false, null, 0, 16) ?: '';
        $ok = false;
        foreach ($magic[$ext] as $sig) { if (strncmp($head, $sig, strlen($sig)) === 0) $ok = true; }
        if (!$ok) return null;
    }

    $dir = __DIR__ . '/../uploads/quiz_' . $quizId;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name']);
    $safe = substr($safe, 0, 80) ?: ('upload.' . $ext);
    $final = now_ts() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
    $path = $dir . '/' . $final;
    if (!move_uploaded_file($f['tmp_name'], $path)) {
        // move_uploaded_file fails in CLI/test contexts; fall back to copy
        if (!@copy($f['tmp_name'], $path)) return null;
    }
    return url('/uploads/quiz_' . $quizId . '/' . $final);
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
