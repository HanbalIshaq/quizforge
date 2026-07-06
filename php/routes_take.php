<?php
/** Step 3 routes: public take-quiz flow + grading + results + admin results. */

declare(strict_types=1);

/** Load a published quiz by share code or 404. */
function get_public_quiz(string $code): array
{
    $q = DB::one("SELECT * FROM quizzes WHERE share_code = ?", [$code]);
    if (!$q || !$q['is_published']) {
        http_response_code(404);
        page('error', ['title'=>'Not found','code'=>404,'message'=>'This quiz is not available.']);
        exit;
    }
    return $q;
}

// ── Take page ─────────────────────────────────────────────────────────────
route('GET', '/q/{code}', function ($p) {
    $quiz = get_public_quiz($p['code']);
    // Password gate
    if (!empty($quiz['quiz_password']) && empty($_SESSION['pass_ok_'.$quiz['id']])) {
        page('student_password', ['title'=>$quiz['title'], 'quiz'=>$quiz, 'bad'=>false]);
        return;
    }
    $questions = quiz_questions((int)$quiz['id']);
    page('student_take', [
        'title' => $quiz['title'],
        'quiz' => $quiz,
        'questions' => $questions,
        'bare' => true,
    ]);
});

// ── Submit (also handles password POST) ──────────────────────────────────
route('POST', '/q/{code}', function ($p) {
    $quiz = get_public_quiz($p['code']);
    $qid = (int)$quiz['id'];

    // Password submission
    if (!empty($quiz['quiz_password']) && empty($_SESSION['pass_ok_'.$qid])) {
        if (isset($_POST['__password'])) {
            if (hash_equals((string)$quiz['quiz_password'], (string)$_POST['__password'])) {
                $_SESSION['pass_ok_'.$qid] = true;
                redirect('/q/'.$quiz['share_code']);
            }
            page('student_password', ['title'=>$quiz['title'], 'quiz'=>$quiz, 'bad'=>true]);
            return;
        }
        // no password provided on a protected quiz
        page('student_password', ['title'=>$quiz['title'], 'quiz'=>$quiz, 'bad'=>true]);
        return;
    }

    $isScored = $quiz['kind'] === 'exam';
    $isSurvey = $quiz['kind'] === 'survey';
    $name = $isSurvey ? 'Anonymous' : (trim((string)($_POST['student_name'] ?? '')) ?: 'Anonymous');
    $email = trim((string)($_POST['student_email'] ?? ''));

    // Server-side required checks
    if (!$isSurvey && $quiz['require_name'] && ($name === '' || $name === 'Anonymous')) {
        flash('Please enter your name.', 'error');
        redirect('/q/'.$quiz['share_code']);
    }
    if ($quiz['require_email'] && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Please enter a valid email.', 'error');
        redirect('/q/'.$quiz['share_code']);
    }

    $questions = quiz_questions($qid);
    $now = now_ts();
    $attemptId = DB::insert(
        "INSERT INTO attempts(quiz_id, student_name, student_email, started_at, submitted_at, ip_address)
         VALUES(?,?,?,?,?,?)",
        [$qid, $name, $email, $now, $now, $_SERVER['REMOTE_ADDR'] ?? '']
    );

    $total = 0.0; $max = 0.0; $needsGrading = 0;
    $rows = [];
    // sort by id for deterministic FK lock order (matches Python fix)
    usort($questions, fn($a,$b) => $a['id'] <=> $b['id']);
    foreach ($questions as $q) {
        if ($q['type'] === 'section_break') continue;
        $max += (float)$q['points'];
        if ($q['type'] === 'file_upload') {
            $val = handle_file_upload('q_'.$q['id'], $qid);
        } else {
            $raw = $_POST['q_'.$q['id']] ?? null;
            $val = parse_submitted_answer($q['type'], $raw);
        }
        if ($isScored) {
            [$isCorrect, $pts, $manual] = grade_answer($q, $val);
        } else {
            [$isCorrect, $pts, $manual] = [null, 0.0, false];
        }
        if ($manual) $needsGrading = 1;
        if ($isCorrect) $total += $pts;
        $rows[] = [$attemptId, $q['id'], json_encode($val), $isCorrect===null?null:($isCorrect?1:0), $pts, $manual?0:1];
    }
    if ($rows) {
        DB::insertMany(
            "INSERT INTO answers(attempt_id, question_id, answer, is_correct, points_earned, graded) VALUES(?,?,?,?,?,?)",
            $rows
        );
    }
    $pct = $max > 0 ? ($total / $max * 100) : 0;
    DB::run("UPDATE attempts SET score=?, max_score=?, percentage=?, needs_grading=? WHERE id=?",
        [$total, $max, $pct, $needsGrading, $attemptId]);

    // Auto-issue a certificate if the exam was passed
    $attemptRow = DB::one("SELECT * FROM attempts WHERE id=?", [$attemptId]);
    issue_certificate_if_passed($quiz, $attemptRow);

    $_SESSION['result_'.$qid] = $attemptId;
    redirect('/q/'.$quiz['share_code'].'/done');
});

// ── Student result page ───────────────────────────────────────────────────
route('GET', '/q/{code}/done', function ($p) {
    $quiz = get_public_quiz($p['code']);
    $attemptId = (int)($_SESSION['result_'.$quiz['id']] ?? 0);
    $attempt = $attemptId ? DB::one("SELECT * FROM attempts WHERE id=? AND quiz_id=?", [$attemptId, $quiz['id']]) : null;
    if (!$attempt) { redirect('/q/'.$quiz['share_code']); }
    $questions = quiz_questions((int)$quiz['id']);
    $answers = [];
    foreach (DB::all("SELECT * FROM answers WHERE attempt_id=?", [$attemptId]) as $a) {
        $a['value'] = json_col($a['answer'], null);
        $answers[(int)$a['question_id']] = $a;
    }
    $cert = DB::one("SELECT serial FROM certificates WHERE attempt_id=?", [$attemptId]);
    page('student_result', [
        'title' => 'Result · '.$quiz['title'],
        'quiz'=>$quiz, 'attempt'=>$attempt, 'questions'=>$questions, 'answers'=>$answers,
        'cert_serial' => $cert['serial'] ?? null, 'bare'=>true,
    ]);
});

// ── Certificate download (public — anyone with the serial) ────────────────
route('GET', '/cert/{serial}.pdf', function ($p) {
    $cert = DB::one("SELECT * FROM certificates WHERE serial=?", [$p['serial']]);
    if (!$cert) { http_response_code(404); page('error',['title'=>'Not found','code'=>404,'message'=>'Certificate not found.']); return; }
    $pdf = certificate_pdf_bytes($cert);
    $name = preg_replace('/[^A-Za-z0-9]/','_', $cert['recipient_name'] ?: 'certificate');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$name.'_'.$cert['serial'].'.pdf"');
    header('Content-Length: '.strlen($pdf));
    echo $pdf; exit;
});

// ── Certificate verification (public) ─────────────────────────────────────
route('GET', '/verify/{serial}', function ($p) {
    $cert = DB::one(
        "SELECT c.*, q.title AS quiz_title FROM certificates c JOIN quizzes q ON q.id=c.quiz_id WHERE c.serial=?",
        [$p['serial']]
    );
    page('cert_verify', ['title'=>'Verify certificate', 'cert'=>$cert, 'bare'=>true]);
});

// ── Admin: results (attempts table for exam/form, aggregate for poll/survey) ─
route('GET', '/admin/quizzes/{id}/results', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    $respCount = (int) DB::scalar("SELECT COUNT(*) FROM attempts WHERE quiz_id=? AND submitted_at IS NOT NULL", [$quiz['id']]);
    if (in_array($quiz['kind'], ['poll','survey'], true)) {
        page('poll_results', [
            'title' => 'Results · '.$quiz['title'],
            'quiz' => $quiz,
            'agg' => quiz_aggregate((int)$quiz['id']),
            'respCount' => $respCount,
        ]);
        return;
    }
    $attempts = DB::all(
        "SELECT * FROM attempts WHERE quiz_id=? AND submitted_at IS NOT NULL ORDER BY submitted_at DESC",
        [$quiz['id']]
    );
    $nq = (int) DB::scalar("SELECT COUNT(*) FROM questions WHERE quiz_id=? AND type<>'section_break'", [$quiz['id']]);
    page('admin_results', ['title'=>'Results · '.$quiz['title'], 'quiz'=>$quiz, 'attempts'=>$attempts, 'nq'=>$nq]);
});

// ── Admin: CSV export ─────────────────────────────────────────────────────
route('GET', '/admin/quizzes/{id}/export.csv', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    $questions = quiz_questions((int)$quiz['id']);
    $questions = array_values(array_filter($questions, fn($q)=>$q['type']!=='section_break'));
    $attempts = DB::all("SELECT * FROM attempts WHERE quiz_id=? AND submitted_at IS NOT NULL ORDER BY submitted_at", [$quiz['id']]);

    $fname = preg_replace('/[^A-Za-z0-9_-]/','_', $quiz['title']) . '_results.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    // Header row
    $head = ['Name','Email','Submitted'];
    if ($quiz['kind']==='exam') { $head[] = 'Score'; $head[] = 'Max'; $head[] = 'Percentage'; }
    foreach ($questions as $i => $q) $head[] = 'Q'.($i+1);
    fputcsv($out, $head);
    foreach ($attempts as $a) {
        $ansRows = DB::all("SELECT question_id, answer FROM answers WHERE attempt_id=?", [$a['id']]);
        $ansMap = [];
        foreach ($ansRows as $r) { $ansMap[(int)$r['question_id']] = json_col($r['answer'], null); }
        $row = [$a['student_name'] ?: 'Anonymous', $a['student_email'], fmt_ts($a['submitted_at'])];
        if ($quiz['kind']==='exam') { $row[] = $a['score']; $row[] = $a['max_score']; $row[] = round((float)$a['percentage']).'%'; }
        foreach ($questions as $q) {
            $v = $ansMap[(int)$q['id']] ?? null;
            if (!empty($q['options']) && is_array($q['options'])) {
                if (is_array($v)) $v = implode('; ', array_map(fn($i)=>$q['options'][$i] ?? $i, $v));
                elseif ($v !== null) $v = $q['options'][(int)$v] ?? $v;
            } elseif (is_array($v)) { $v = implode('; ', $v); }
            $row[] = (string)($v ?? '');
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
});

// ── Admin: attempt detail + manual grading ────────────────────────────────
route('GET', '/admin/quizzes/{id}/attempts/{aid}', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    $attempt = DB::one("SELECT * FROM attempts WHERE id=? AND quiz_id=?", [(int)$p['aid'], $quiz['id']]);
    if (!$attempt) { http_response_code(404); page('error',['title'=>'Not found','code'=>404,'message'=>'Attempt not found.']); return; }
    $questions = quiz_questions((int)$quiz['id']);
    $answers = [];
    foreach (DB::all("SELECT * FROM answers WHERE attempt_id=?", [$attempt['id']]) as $a) {
        $a['value'] = json_col($a['answer'], null);
        $answers[(int)$a['question_id']] = $a;
    }
    page('admin_attempt', ['title'=>'Attempt · '.($attempt['student_name']?:'Anonymous'), 'quiz'=>$quiz, 'attempt'=>$attempt, 'questions'=>$questions, 'answers'=>$answers]);
});

route('POST', '/admin/quizzes/{id}/attempts/{aid}', function ($p) {
    require_login();
    $quiz = get_owned_quiz((int)$p['id']);
    $attempt = DB::one("SELECT * FROM attempts WHERE id=? AND quiz_id=?", [(int)$p['aid'], $quiz['id']]);
    if (!$attempt) { redirect('/admin/quizzes/'.$quiz['id'].'/results'); }
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'pts_') === 0) {
            $ansId = (int)substr($k, 4);
            $pts = (float)$v;
            DB::run("UPDATE answers SET points_earned=?, graded=1, is_correct=CASE WHEN ?>0 THEN 1 ELSE 0 END WHERE id=? AND attempt_id=?",
                [$pts, $pts, $ansId, $attempt['id']]);
        } elseif (strpos($k, 'fb_') === 0) {
            $ansId = (int)substr($k, 3);
            DB::run("UPDATE answers SET feedback=? WHERE id=? AND attempt_id=?", [(string)$v, $ansId, $attempt['id']]);
        }
    }
    $earned = (float) DB::scalar("SELECT COALESCE(SUM(points_earned),0) FROM answers WHERE attempt_id=?", [$attempt['id']]);
    $maxScore = (float)$attempt['max_score'] ?: 1;
    $pct = $maxScore > 0 ? ($earned / $maxScore * 100) : 0;
    DB::run("UPDATE attempts SET score=?, percentage=?, needs_grading=0 WHERE id=?", [$earned, $pct, $attempt['id']]);
    flash('Grading saved.', 'success');
    redirect('/admin/quizzes/'.$quiz['id'].'/attempts/'.$attempt['id']);
});
