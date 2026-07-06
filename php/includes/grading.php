<?php
/**
 * Grading engine + question-type catalogue. Ported from the Python grading.py.
 *
 * grade_answer($question, $answer) returns [is_correct, points_earned, needs_manual]
 * where is_correct may be null for ungradable types (polls, open text).
 */

declare(strict_types=1);

/** All supported types: [value, label, group]. Group drives editor sections. */
function question_types(): array
{
    return [
        // group: choice
        ['mcq_single', 'Multiple Choice (single answer)', 'choice'],
        ['mcq_multi', 'Multiple Choice (multiple answers)', 'choice'],
        ['true_false', 'True / False (or Yes / No)', 'choice'],
        ['dropdown', 'Dropdown / Select', 'choice'],
        // group: text
        ['short_answer', 'Short Answer', 'text'],
        ['long_answer', 'Long Answer / Essay', 'text'],
        ['fill_blank', 'Fill in the Blank', 'text'],
        // group: scale
        ['rating', 'Rating (1–5 stars)', 'scale'],
        ['nps', 'NPS — Net Promoter Score (0–10)', 'scale'],
        ['slider', 'Slider / Range', 'scale'],
        // group: poll
        ['poll', 'Poll choice (no correct answer)', 'poll'],
        ['open_ended', 'Open-ended (free text, ungraded)', 'poll'],
        ['word_cloud', 'Word Cloud (live)', 'poll'],
        // group: interactive
        ['matching', 'Matching (pair left with right)', 'interactive'],
        ['ordering', 'Ordering / Sequencing', 'interactive'],
        ['drag_drop', 'Drag & Drop (sort into categories)', 'interactive'],
        ['hotspot', 'Image Hotspot (click the area)', 'interactive'],
        // group: form fields
        ['email', 'Email address', 'form'],
        ['phone', 'Phone number', 'form'],
        ['number', 'Number', 'form'],
        ['date', 'Date picker', 'form'],
        ['time', 'Time picker', 'form'],
        ['datetime', 'Date & Time', 'form'],
        ['url', 'URL / Website', 'form'],
        ['address', 'Full address', 'form'],
        ['full_name', 'Full name (first + last)', 'form'],
        ['file_upload', 'File upload', 'form'],
        ['signature', 'Signature pad', 'form'],
        ['consent', 'Terms / Consent checkbox', 'form'],
        ['section_break', 'Section break / instructions', 'form'],
    ];
}

/** Types that are auto-graded to correct/incorrect. */
function auto_graded_types(): array
{
    return ['mcq_single', 'mcq_multi', 'true_false', 'short_answer', 'fill_blank', 'dropdown'];
}

/** Types that always need manual grading. */
function manual_types(): array
{
    return ['long_answer'];
}

/** Types that have an options list (choices). */
function choice_types(): array
{
    return ['mcq_single', 'mcq_multi', 'true_false', 'dropdown', 'poll'];
}

function normalize_text(?string $s): string
{
    return implode(' ', preg_split('/\s+/', trim(mb_strtolower((string)$s))));
}

/**
 * Grade a single answer.
 * @param array $question  row with type/options/correct_answers/points
 * @param mixed $answer    decoded submitted value
 * @return array [?bool is_correct, float points_earned, bool needs_manual]
 */
function grade_answer(array $question, $answer): array
{
    $type = $question['type'];
    $points = (float)($question['points'] ?? 1);
    $correct = $question['correct_answers'] ?? [];
    if (is_string($correct)) $correct = json_col($correct, []);
    $correct = is_array($correct) ? $correct : [];
    $options = $question['options'] ?? [];
    if (is_string($options)) $options = json_col($options, []);
    $options = is_array($options) ? $options : [];

    $empty = ($answer === null || $answer === '' || $answer === []);
    if ($empty) {
        $isAuto = in_array($type, auto_graded_types(), true);
        return [$isAuto ? false : null, 0.0, in_array($type, manual_types(), true)];
    }

    switch ($type) {
        case 'mcq_single':
        case 'true_false':
        case 'dropdown':
            $picked = is_array($answer) ? ($answer[0] ?? null) : $answer;
            $pi = to_int($picked, null);
            if ($pi === null) return [false, 0.0, false];
            $ok = in_array($pi, int_list($correct), true);
            return [$ok, $ok ? $points : 0.0, false];

        case 'mcq_multi':
            $picked = is_array($answer) ? $answer : [$answer];
            $pi = int_list($picked); sort($pi);
            $ci = int_list($correct); sort($ci);
            $ok = ($pi === $ci);
            return [$ok, $ok ? $points : 0.0, false];

        case 'short_answer':
        case 'fill_blank':
            $val = is_array($answer) ? ($answer[0] ?? '') : $answer;
            $accepted = array_map('normalize_text', $correct);
            if (!$accepted) return [null, 0.0, true];
            $ok = in_array(normalize_text((string)$val), $accepted, true);
            return [$ok, $ok ? $points : 0.0, false];

        case 'long_answer':
            return [null, 0.0, true];

        case 'matching':
            // options: [{a,b}], answer: [picked b for each index]
            if (!is_array($answer) || !$options) return [false, 0.0, false];
            $okc = 0;
            foreach ($options as $i => $pair) {
                $exp = is_array($pair) ? ($pair['b'] ?? '') : '';
                $pick = $answer[$i] ?? '';
                if (normalize_text((string)$pick) === normalize_text((string)$exp)) $okc++;
            }
            $ratio = count($options) ? $okc / count($options) : 0;
            return [$ratio == 1.0, $points * $ratio, false];

        case 'ordering':
            if (!is_array($answer) || !$options) return [false, 0.0, false];
            $order = $correct ? int_list($correct) : range(0, count($options) - 1);
            $pi = int_list($answer);
            $ok = ($pi === $order);
            return [$ok, $ok ? $points : 0.0, false];

        case 'drag_drop':
            // options: [{item,bin}], answer: [picked bin per item]
            if (!is_array($answer) || !$options) return [false, 0.0, false];
            $okc = 0;
            foreach ($options as $i => $pair) {
                $exp = is_array($pair) ? ($pair['bin'] ?? '') : '';
                $pick = $answer[$i] ?? '';
                if (normalize_text((string)$pick) === normalize_text((string)$exp)) $okc++;
            }
            $ratio = count($options) ? $okc / count($options) : 0;
            return [$ratio == 1.0, $points * $ratio, false];

        case 'hotspot':
            // options: [{x,y,r}], answer: {x,y}
            if (!is_array($answer)) return [false, 0.0, false];
            $x = (float)($answer['x'] ?? -1);
            $y = (float)($answer['y'] ?? -1);
            foreach ($options as $hs) {
                $hx = (float)($hs['x'] ?? 0); $hy = (float)($hs['y'] ?? 0); $hr = (float)($hs['r'] ?? 0.05);
                if (sqrt(($x-$hx)**2 + ($y-$hy)**2) <= $hr) return [true, $points, false];
            }
            return [false, 0.0, false];

        default:
            // poll, rating, nps, form fields — collected, not graded
            return [null, 0.0, false];
    }
}
