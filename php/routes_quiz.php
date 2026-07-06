<?php
/** Step 2 routes: dashboard + quiz CRUD + question editor. */

declare(strict_types=1);

// ── Dashboard ──────────────────────────────────────────────────────────
route('GET', '/admin', function () {
    require_login();
    $uid = (int)$_SESSION['uid'];
    $kindFilter = $_GET['kind'] ?? 'all';
    if (in_array($kindFilter, ['exam','poll','survey','form'], true)) {
        $quizzes = DB::all(
            "SELECT q.*,
                    (SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) AS n_q,
                    (SELECT COUNT(*) FROM attempts WHERE quiz_id=q.id AND submitted_at IS NOT NULL) AS n_a
             FROM quizzes q WHERE user_id=? AND kind=? ORDER BY updated_at DESC",
            [$uid, $kindFilter]
        );
    } else {
        $quizzes = DB::all(
            "SELECT q.*,
                    (SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) AS n_q,
                    (SELECT COUNT(*) FROM attempts WHERE quiz_id=q.id AND submitted_at IS NOT NULL) AS n_a
             FROM quizzes q WHERE user_id=? ORDER BY updated_at DESC",
            [$uid]
        );
    }
    page('dashboard', [
        'title' => 'Dashboard · ' . app_name(),
        'u' => current_user(),
        'quizzes' => $quizzes,
        'counts' => quiz_kind_counts($uid),
        'kindFilter' => $kindFilter,
    ]);
});

// ── Seed demo data (one-click sample quizzes + responses) ─────────────────
route('POST', '/admin/seed-demo', function () {
    require_login();
    require_once __DIR__ . '/includes/seed.php';
    $summary = seed_demo_data((int)$_SESSION['uid']);
    flash("Loaded {$summary['quizzes']} demo quizzes with {$summary['submissions']} sample responses. Explore them below.", 'success');
    redirect('/admin');
});

// ── Create quiz ──────────────────────────────────────────────────────────
route('POST', '/admin/quizzes/new', function () {
    require_login();
    $uid = (int)$_SESSION['uid'];
    $kind = $_POST['kind'] ?? 'exam';
    $title = trim($_POST['title'] ?? '');
    if ($title === '') { flash('Please enter a title.', 'error'); redirect('/admin'); }
    if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 200);
    $r = create_quiz($uid, $kind, $title);
    flash('Created "' . $title . '". Add your ' . ($kind === 'form' ? 'fields' : 'questions') . ' below.', 'success');
    redirect('/admin/quizzes/' . $r['id']);
});

// ── Quiz editor ──────────────────────────────────────────────────────────
route('GET', '/admin/quizzes/{id}', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    $questions = quiz_questions((int)$quiz['id']);
    page('quiz_edit', [
        'title' => $quiz['title'] . ' · Edit',
        'quiz' => $quiz,
        'questions' => $questions,
        'types' => question_types(),
    ]);
});

// ── Save settings (AJAX autosave OR form POST) ────────────────────────────
route('POST', '/admin/quizzes/{id}/settings', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    // Whitelist: field => type
    $bool = ['randomize_questions','randomize_options','show_correct_answers','require_name',
             'require_email','is_published','paginated','anti_paste','anti_rightclick',
             'block_selection','require_fullscreen','detect_tab_switch','detect_devtools','camera_proctor'];
    $int  = ['time_limit_seconds','pass_mark','max_attempts','violation_limit','proctor_snapshot_interval'];
    $str  = ['title','description','kind','quiz_password','ip_allowlist'];
    $sets = []; $vals = [];
    foreach ($bool as $f) { if (array_key_exists($f, $_POST)) { $sets[]="$f=?"; $vals[]= ($_POST[$f] === '1' || $_POST[$f]==='true' || $_POST[$f]==='on') ? 1 : 0; } }
    foreach ($int as $f)  { if (array_key_exists($f, $_POST)) { $sets[]="$f=?"; $vals[]= max(0, to_int($_POST[$f], 0)); } }
    foreach ($str as $f)  { if (array_key_exists($f, $_POST)) { $v = trim((string)$_POST[$f]); if ($f==='kind' && !in_array($v,['exam','poll','survey','form'],true)) continue; $sets[]="$f=?"; $vals[]=$v; } }
    if ($sets) {
        $sets[] = 'updated_at=?'; $vals[] = now_ts();
        $vals[] = $quiz['id'];
        DB::run("UPDATE quizzes SET " . implode(',', $sets) . " WHERE id=?", $vals);
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch' || ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]); exit;
    }
    flash('Settings saved.', 'success');
    redirect('/admin/quizzes/' . $quiz['id']);
});

// ── Add or update a question ──────────────────────────────────────────────
route('POST', '/admin/quizzes/{id}/questions', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    [$type, $text, $optionsJson, $correctJson, $points, $explanation, $timeLimit, $isRequired] = parse_question_form();
    if ($text === '') { flash('Question text is required.', 'error'); redirect('/admin/quizzes/' . $quiz['id']); }
    $qid = to_int($_POST['qid'] ?? '', null);
    if ($qid) {
        // update — verify it belongs to this quiz
        $own = DB::one("SELECT id FROM questions WHERE id=? AND quiz_id=?", [$qid, $quiz['id']]);
        if ($own) {
            DB::run("UPDATE questions SET type=?, text=?, options=?, correct_answers=?, points=?, explanation=?, time_limit_seconds=?, is_required=? WHERE id=?",
                [$type, $text, $optionsJson, $correctJson, $points, $explanation, $timeLimit, $isRequired, $qid]);
            flash('Question updated.', 'success');
        }
    } else {
        $pos = (int) (DB::scalar("SELECT COALESCE(MAX(position),-1)+1 FROM questions WHERE quiz_id=?", [$quiz['id']]) ?? 0);
        DB::insert("INSERT INTO questions(quiz_id, type, text, options, correct_answers, points, position, explanation, time_limit_seconds, is_required)
                    VALUES(?,?,?,?,?,?,?,?,?,?)",
            [$quiz['id'], $type, $text, $optionsJson, $correctJson, $points, $pos, $explanation, $timeLimit, $isRequired]);
        flash('Question added.', 'success');
    }
    DB::run("UPDATE quizzes SET updated_at=? WHERE id=?", [now_ts(), $quiz['id']]);
    redirect('/admin/quizzes/' . $quiz['id']);
});

// ── Bulk import questions ─────────────────────────────────────────────────
route('POST', '/admin/quizzes/{id}/import', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    $format = $_POST['format'] ?? 'text';
    $raw = (string)($_POST['content'] ?? '');
    // Uploaded file takes precedence
    if (!empty($_FILES['file']['tmp_name']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if (($_FILES['file']['size'] ?? 0) <= 2 * 1024 * 1024) {
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['csv','txt','json'], true)) {
                $raw = (string) file_get_contents($_FILES['file']['tmp_name']);
                $format = $ext === 'txt' ? 'text' : $ext;
            }
        }
    }
    if (trim($raw) === '') { flash('Nothing to import — paste content or choose a file.', 'error'); redirect('/admin/quizzes/'.$quiz['id']); }
    $count = import_questions_into_quiz((int)$quiz['id'], $format, $raw);
    if ($count) flash("Imported $count question(s).", 'success');
    else flash('Could not parse any questions. Check the format.', 'error');
    redirect('/admin/quizzes/'.$quiz['id']);
});

// ── Delete a question ─────────────────────────────────────────────────────
route('POST', '/admin/quizzes/{id}/questions/{qid}/delete', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    DB::run("DELETE FROM questions WHERE id=? AND quiz_id=?", [(int)$p['qid'], $quiz['id']]);
    DB::run("UPDATE quizzes SET updated_at=? WHERE id=?", [now_ts(), $quiz['id']]);
    flash('Question deleted.', 'success');
    redirect('/admin/quizzes/' . $quiz['id']);
});

// ── Delete quiz ───────────────────────────────────────────────────────────
route('POST', '/admin/quizzes/{id}/delete', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    // Manual cascade (works on MySQL + SQLite regardless of FK enforcement)
    $attempts = DB::all("SELECT id FROM attempts WHERE quiz_id=?", [$quiz['id']]);
    foreach ($attempts as $a) {
        DB::run("DELETE FROM answers WHERE attempt_id=?", [$a['id']]);
        DB::run("DELETE FROM violations WHERE attempt_id=?", [$a['id']]);
        DB::run("DELETE FROM proctor_snapshots WHERE attempt_id=?", [$a['id']]);
    }
    DB::run("DELETE FROM attempts WHERE quiz_id=?", [$quiz['id']]);
    DB::run("DELETE FROM questions WHERE quiz_id=?", [$quiz['id']]);
    DB::run("DELETE FROM quizzes WHERE id=?", [$quiz['id']]);
    flash('Quiz deleted.', 'success');
    redirect('/admin');
});


/**
 * Parse the shared add/edit question form into normalized values.
 * @return array [type, text, optionsJson, correctJson, points, explanation, timeLimit, isRequired]
 */
function parse_question_form(): array
{
    $type = $_POST['type'] ?? 'mcq_single';
    $allTypes = array_column(question_types(), 0);
    if (!in_array($type, $allTypes, true)) $type = 'mcq_single';
    $text = trim((string)($_POST['text'] ?? ''));
    $points = max(0, to_int($_POST['points'] ?? 1, 1));
    $explanation = trim((string)($_POST['explanation'] ?? ''));
    $timeLimit = max(0, to_int($_POST['time_limit_seconds'] ?? 0, 0));
    $isRequired = (($_POST['is_required'] ?? '1') === '0') ? 0 : 1;

    $options = [];
    $correct = [];

    if (in_array($type, ['mcq_single','mcq_multi','true_false','dropdown','poll'], true)) {
        // options come as options[] ; correct radios/checkboxes as correct[] (indices)
        $rawOpts = $_POST['options'] ?? [];
        if (!is_array($rawOpts)) $rawOpts = [];
        $idx = 0; $indexMap = [];
        foreach ($rawOpts as $origIdx => $opt) {
            $opt = trim((string)$opt);
            if ($opt === '') continue;
            $indexMap[(string)$origIdx] = $idx;   // remap sparse indices to dense
            $options[] = $opt;
            $idx++;
        }
        if ($type === 'true_false' && !$options) {
            $options = ['True', 'False'];
        }
        $rawCorrect = $_POST['correct'] ?? [];
        if (!is_array($rawCorrect)) $rawCorrect = [$rawCorrect];
        foreach ($rawCorrect as $c) {
            $key = (string)$c;
            if (isset($indexMap[$key])) $correct[] = $indexMap[$key];
            elseif ($type === 'true_false') $correct[] = to_int($c, null);
        }
        $correct = int_list($correct);
        if ($type === 'mcq_single' || $type === 'true_false' || $type === 'dropdown') {
            $correct = $correct ? [$correct[0]] : [];
        }
    } elseif (in_array($type, ['short_answer','fill_blank'], true)) {
        // accepted answers, one per line
        $raw = (string)($_POST['accepted'] ?? '');
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') $correct[] = $line;
        }
    }
    // long_answer, form fields, rating/nps/etc: no options/correct needed here

    return [$type, $text, json_encode($options), json_encode($correct), $points, $explanation, $timeLimit, $isRequired];
}
