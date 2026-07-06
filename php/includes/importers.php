<?php
/**
 * Bulk-import parsers. Each returns a list of normalized question arrays:
 *   ['type','text','options'(array),'correct'(array),'points','explanation']
 * ready to insert. Supports CSV, plain text (Aiken-ish), and JSON.
 */

declare(strict_types=1);

/** Letter (A/B/C…) or 1-based number to a 0-based index. */
function _letter_to_index(string $s): ?int
{
    $s = strtoupper(trim($s));
    if ($s === '') return null;
    if (ctype_alpha($s) && strlen($s) === 1) return ord($s) - 65;
    if (ctype_digit($s)) return max(0, (int)$s - 1);
    return null;
}

/**
 * CSV format:
 *   type,text,option1,option2,option3,option4,correct,points
 * correct: letter(s) A/B/C or number(s), pipe-separated for multi (e.g. A|C).
 * For short_answer/fill_blank/true_false put the answer text/True|False in
 * the `correct` column and leave options blank.
 */
function import_parse_csv(string $raw): array
{
    $out = [];
    $lines = preg_split('/\r?\n/', trim($raw));
    if (!$lines) return $out;
    // detect + skip a header row
    $first = str_getcsv($lines[0]);
    $start = 0;
    if ($first && strtolower(trim($first[0])) === 'type') $start = 1;
    for ($i = $start; $i < count($lines); $i++) {
        if (trim($lines[$i]) === '') continue;
        $c = str_getcsv($lines[$i]);
        $type = strtolower(trim($c[0] ?? 'mcq_single'));
        $text = trim($c[1] ?? '');
        if ($text === '') continue;
        // options are columns 2..(n-2), correct = second-to-last, points = last
        $n = count($c);
        $points = is_numeric(trim($c[$n-1] ?? '1')) ? (int)trim($c[$n-1]) : 1;
        $correctRaw = trim($c[$n-2] ?? '');
        $opts = [];
        for ($k = 2; $k < $n - 2; $k++) { $o = trim($c[$k] ?? ''); if ($o !== '') $opts[] = $o; }
        $q = ['type'=>$type, 'text'=>$text, 'options'=>$opts, 'correct'=>[], 'points'=>$points, 'explanation'=>''];

        if (in_array($type, ['mcq_single','mcq_multi','true_false','dropdown'], true)) {
            if ($type === 'true_false' && !$opts) { $q['options'] = ['True','False']; $opts = $q['options']; }
            foreach (explode('|', $correctRaw) as $part) {
                $idx = _letter_to_index($part);
                if ($idx === null && $type === 'true_false') {
                    $idx = (stripos($part,'t')===0 || stripos($part,'y')===0) ? 0 : 1;
                }
                if ($idx !== null) $q['correct'][] = $idx;
            }
        } elseif (in_array($type, ['short_answer','fill_blank'], true)) {
            foreach (explode('|', $correctRaw) as $part) { $part=trim($part); if($part!=='') $q['correct'][]=$part; }
        }
        $out[] = $q;
    }
    return $out;
}

/**
 * Plain-text (Aiken-style) format. Blank line separates questions.
 *   What is 2+2?
 *   A) 3
 *   B) 4
 *   ANSWER: B
 * Also supports:
 *   The sky is blue.
 *   ANSWER: True
 * and short answer:
 *   Q: Capital of France?
 *   A: Paris
 */
function import_parse_text(string $raw): array
{
    $out = [];
    $blocks = preg_split('/\n\s*\n/', trim($raw));
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $block)), fn($l)=>$l!==''));
        if (!$lines) continue;

        // Short answer: Q:/A:
        if (stripos($lines[0], 'Q:') === 0) {
            $text = trim(substr($lines[0], 2));
            $ans = '';
            foreach ($lines as $l) if (stripos($l,'A:')===0) $ans = trim(substr($l,2));
            $out[] = ['type'=>'short_answer','text'=>$text,'options'=>[],'correct'=>$ans!==''?[$ans]:[],'points'=>1,'explanation'=>''];
            continue;
        }

        $text = $lines[0];
        $opts = []; $answer = null;
        for ($i = 1; $i < count($lines); $i++) {
            $l = $lines[$i];
            if (stripos($l, 'ANSWER:') === 0) { $answer = trim(substr($l, 7)); continue; }
            // "A) foo" or "A. foo" or "A: foo"
            if (preg_match('/^([A-Za-z])[\).:]\s*(.+)$/', $l, $m)) { $opts[] = trim($m[2]); }
            else { $opts[] = $l; }
        }

        if ($answer !== null && preg_match('/^(true|false|yes|no)$/i', $answer)) {
            $out[] = ['type'=>'true_false','text'=>$text,'options'=>['True','False'],
                      'correct'=>[preg_match('/^(true|yes)$/i',$answer)?0:1],'points'=>1,'explanation'=>''];
        } elseif ($opts) {
            $correct = [];
            foreach (explode(',', (string)$answer) as $a) { $idx=_letter_to_index($a); if($idx!==null)$correct[]=$idx; }
            $type = count($correct) > 1 ? 'mcq_multi' : 'mcq_single';
            $out[] = ['type'=>$type,'text'=>$text,'options'=>$opts,'correct'=>$correct,'points'=>1,'explanation'=>''];
        } else {
            // no options + no A:/Q: -> short answer with the ANSWER: value
            $out[] = ['type'=>'short_answer','text'=>$text,'options'=>[],
                      'correct'=>$answer!==null?[$answer]:[],'points'=>1,'explanation'=>''];
        }
    }
    return $out;
}

/** JSON: an array of {type,text,options,correct_answers|correct,points,explanation}. */
function import_parse_json(string $raw): array
{
    $data = json_decode(trim($raw), true);
    if (!is_array($data)) return [];
    // allow {"questions":[...]} or a bare array
    if (isset($data['questions']) && is_array($data['questions'])) $data = $data['questions'];
    $out = [];
    foreach ($data as $q) {
        if (!is_array($q) || empty($q['text'])) continue;
        $correct = $q['correct'] ?? $q['correct_answers'] ?? [];
        if (!is_array($correct)) $correct = [$correct];
        $out[] = [
            'type' => $q['type'] ?? 'mcq_single',
            'text' => (string)$q['text'],
            'options' => is_array($q['options'] ?? null) ? $q['options'] : [],
            'correct' => $correct,
            'points' => (int)($q['points'] ?? 1),
            'explanation' => (string)($q['explanation'] ?? ''),
        ];
    }
    return $out;
}

/** Dispatch by format string; insert into a quiz. Returns count inserted. */
function import_questions_into_quiz(int $quizId, string $format, string $raw): int
{
    $format = strtolower($format);
    if ($format === 'csv')       $qs = import_parse_csv($raw);
    elseif ($format === 'json')  $qs = import_parse_json($raw);
    else                         $qs = import_parse_text($raw);

    if (!$qs) return 0;
    $allTypes = array_column(question_types(), 0);
    $pos = (int) (DB::scalar("SELECT COALESCE(MAX(position),-1)+1 FROM questions WHERE quiz_id=?", [$quizId]) ?? 0);
    $count = 0;
    foreach ($qs as $q) {
        $type = in_array($q['type'], $allTypes, true) ? $q['type'] : 'mcq_single';
        DB::insert(
            "INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position,explanation,time_limit_seconds,is_required)
             VALUES(?,?,?,?,?,?,?,?,0,1)",
            [$quizId, $type, $q['text'], json_encode($q['options']), json_encode(array_values($q['correct'])),
             max(0,(int)$q['points']), $pos++, $q['explanation']]
        );
        $count++;
    }
    if ($count) DB::run("UPDATE quizzes SET updated_at=? WHERE id=?", [now_ts(), $quizId]);
    return $count;
}
