<?php
/**
 * Demo-data seeder. Creates representative quizzes across kinds + question
 * types AND generates realistic sample responses so every dashboard has data
 * to show — instead of building it all by hand.
 *
 * Reuses the real grading engine so seeded scores are consistent with what
 * the app would compute for a genuine submission.
 *
 * Usable from CLI (tools/seed_demo.php) or the admin button (POST /admin/seed-demo).
 */

declare(strict_types=1);

/** Insert a quiz + its questions. $questions: list of assoc arrays. */
function _seed_quiz(int $uid, string $kind, string $title, string $desc, array $settings, array $questions): int
{
    $code = unique_code('quizzes', 'share_code', 7);
    $now = now_ts();
    $cols = array_merge([
        'user_id'=>$uid,'title'=>$title,'description'=>$desc,'share_code'=>$code,'kind'=>$kind,
        'created_at'=>$now,'updated_at'=>$now,'is_published'=>1,
        'require_name'=> $kind==='survey'?0:1,'require_email'=>0,
        'show_correct_answers'=> $kind==='exam'?1:0,'paginated'=> $kind==='form'?1:0,
        'pass_mark'=> $kind==='exam'?50:0,
    ], $settings);
    $fields = implode(',', array_keys($cols));
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $qid = DB::insert("INSERT INTO quizzes($fields) VALUES($ph)", array_values($cols));
    foreach ($questions as $pos => $q) {
        DB::insert(
            "INSERT INTO questions(quiz_id,type,text,options,correct_answers,points,position,explanation,time_limit_seconds,is_required)
             VALUES(?,?,?,?,?,?,?,?,?,?)",
            [$qid, $q['type'], $q['text'], json_encode($q['options'] ?? []), json_encode($q['correct'] ?? []),
             $q['points'] ?? 1, $pos, $q['explanation'] ?? '', 0, $q['required'] ?? 1]
        );
    }
    return $qid;
}

/** Generate a plausible answer for a question when seeding a fake submission.
 *  $correctBias 0..1 = probability of answering correctly (scored types). */
function _seed_answer(array $q, float $correctBias)
{
    $type = $q['type'];
    $opts = $q['options'] ?? [];
    $correct = array_map('intval', (array)($q['correct'] ?? []));
    $wantCorrect = (mt_rand(0, 100) / 100) < $correctBias;

    switch ($type) {
        case 'mcq_single': case 'true_false': case 'dropdown':
            if ($wantCorrect && $correct) return $correct[0];
            return mt_rand(0, max(0, count($opts) - 1));
        case 'mcq_multi':
            if ($wantCorrect && $correct) return $correct;
            // random subset
            $pick = [];
            foreach (array_keys($opts) as $i) if (mt_rand(0,1)) $pick[] = $i;
            return $pick ?: [0];
        case 'poll':
            return mt_rand(0, max(0, count($opts) - 1));
        case 'short_answer': case 'fill_blank':
            if ($wantCorrect && !empty($q['correct'])) return $q['correct'][0];
            return 'some answer';
        case 'long_answer':
            return 'This is a sample long-form response for demonstration purposes.';
        case 'rating':
            return $wantCorrect ? mt_rand(4,5) : mt_rand(1,5);
        case 'nps':
            $r = mt_rand(0,100); return $r<55 ? mt_rand(9,10) : ($r<80 ? mt_rand(7,8) : mt_rand(0,6));
        case 'open_ended':
            $c = ['Great experience overall!','Could be better in places.','Loved the content.',
                  'Very helpful, thank you.','The pacing was a bit fast.','Excellent — highly recommend.',
                  'Needs more examples.','Fantastic and clear.'];
            return $c[array_rand($c)];
        case 'email': return strtolower('user'.mt_rand(1,999).'@example.com');
        case 'phone': return '+1 555 '.mt_rand(1000000,9999999);
        case 'number': return (string) mt_rand(18, 65);
        case 'date': return date('Y-m-d', now_ts() - mt_rand(0, 365*86400));
        case 'full_name':
            $fn=['Alex','Sam','Jordan','Priya','Wei','Maria','Omar','Nina']; $ln=['Khan','Lee','Patel','Garcia','Chen','Smith','Ali','Novak'];
            return $fn[array_rand($fn)].' '.$ln[array_rand($ln)];
        case 'consent': return '1';
        case 'address': return mt_rand(1,999).' Demo Street, Springfield';
        default: return 'Sample response';
    }
}

/** Seed everything for a user. Returns a summary array. */
function seed_demo_data(int $uid): array
{
    $created = [];

    // ── 1) Demo Exam (gradable question types) ──
    $exam = _seed_quiz($uid, 'exam', 'Demo Exam — General Knowledge', 'A sample graded exam showcasing many question types.', [], [
        ['type'=>'section_break','text'=>'Section 1 — Multiple choice','required'=>0],
        ['type'=>'mcq_single','text'=>'What is the capital of France?','options'=>['Berlin','Madrid','Paris','Rome'],'correct'=>[2],'points'=>1,'explanation'=>'Paris is the capital of France.'],
        ['type'=>'mcq_multi','text'=>'Select all prime numbers.','options'=>['2','3','4','5','6'],'correct'=>[0,1,3],'points'=>2],
        ['type'=>'true_false','text'=>'The Earth is flat.','options'=>['True','False'],'correct'=>[1],'points'=>1],
        ['type'=>'dropdown','text'=>'Which is the largest planet?','options'=>['Earth','Mars','Jupiter','Venus'],'correct'=>[2],'points'=>1],
        ['type'=>'section_break','text'=>'Section 2 — Written','required'=>0],
        ['type'=>'short_answer','text'=>'What does HTTP stand for?','correct'=>['Hypertext Transfer Protocol','HyperText Transfer Protocol'],'points'=>2],
        ['type'=>'fill_blank','text'=>'The chemical symbol for water is ____.','correct'=>['H2O'],'points'=>1],
        ['type'=>'long_answer','text'=>'Briefly explain the process of photosynthesis.','points'=>3],
    ]);
    $created['exam'] = $exam;

    // ── 2) Demo Poll ──
    $poll = _seed_quiz($uid, 'poll', 'Demo Poll — Team Preferences', 'A quick opinion poll with charts, NPS and open text.', [], [
        ['type'=>'poll','text'=>'Favorite programming language?','options'=>['PHP','Python','JavaScript','Go','Rust']],
        ['type'=>'poll','text'=>'Best day for our weekly meeting?','options'=>['Monday','Wednesday','Friday']],
        ['type'=>'rating','text'=>'Rate last week\'s event.','points'=>0],
        ['type'=>'nps','text'=>'How likely are you to recommend us to a friend?','points'=>0],
        ['type'=>'open_ended','text'=>'Any suggestions for improvement?','required'=>0],
    ]);
    $created['poll'] = $poll;

    // ── 3) Demo Survey (anonymous) ──
    $survey = _seed_quiz($uid, 'survey', 'Demo Survey — Customer Feedback', 'An anonymous feedback survey.', [], [
        ['type'=>'rating','text'=>'Overall, how satisfied are you?','points'=>0],
        ['type'=>'nps','text'=>'How likely are you to recommend our product?','points'=>0],
        ['type'=>'mcq_single','text'=>'How did you hear about us?','options'=>['Search engine','Social media','Friend','Advertisement','Other']],
        ['type'=>'mcq_multi','text'=>'Which features do you use? (select all)','options'=>['Quizzes','Polls','Surveys','Forms','Certificates']],
        ['type'=>'open_ended','text'=>'What can we do better?','required'=>0],
    ]);
    $created['survey'] = $survey;

    // ── 4) Demo Form ──
    $form = _seed_quiz($uid, 'form', 'Demo Form — Event Registration', 'A JotForm-style data-collection form.', ['require_email'=>1], [
        ['type'=>'full_name','text'=>'Full name'],
        ['type'=>'email','text'=>'Email address'],
        ['type'=>'phone','text'=>'Phone number','required'=>0],
        ['type'=>'number','text'=>'Your age','required'=>0],
        ['type'=>'date','text'=>'Preferred attendance date'],
        ['type'=>'dropdown','text'=>'Country','options'=>['United States','United Kingdom','India','Pakistan','Canada','Australia','Other']],
        ['type'=>'long_answer','text'=>'Anything we should know?','required'=>0],
        ['type'=>'consent','text'=>'Consent','options'=>['I agree to the event terms and privacy policy.']],
    ]);
    $created['form'] = $form;

    // ── Generate sample submissions ──
    $names = ['Alice Johnson','Bob Smith','Carlos Diaz','Dina Ahmed','Emily Clark','Farhan Ali','Grace Lee',
              'Hiro Tanaka','Ivan Petrov','Julia Roberts','Karim Hassan','Lena Müller','Mohan Rao','Nadia Khan'];

    $seedSubmissions = function(int $quizId, int $n, float $bias) use ($names) {
        $questions = quiz_questions($quizId);
        $quiz = DB::one("SELECT kind FROM quizzes WHERE id=?", [$quizId]);
        $isScored = $quiz['kind'] === 'exam';
        for ($s = 0; $s < $n; $s++) {
            $name = $quiz['kind']==='survey' ? 'Anonymous' : $names[array_rand($names)];
            $email = $quiz['kind']==='survey' ? '' : strtolower(str_replace(' ','.',$name)).'@example.com';
            $submittedAt = now_ts() - mt_rand(0, 20*86400);
            $aid = DB::insert("INSERT INTO attempts(quiz_id,student_name,student_email,started_at,submitted_at,ip_address) VALUES(?,?,?,?,?,?)",
                [$quizId,$name,$email,$submittedAt,$submittedAt,'127.0.0.1']);
            $total=0.0; $max=0.0; $needsGrading=0; $rows=[];
            foreach ($questions as $q) {
                if ($q['type']==='section_break' || $q['type']==='file_upload' || $q['type']==='signature') continue;
                $max += (float)$q['points'];
                // build the type spec _seed_answer expects
                $spec = ['type'=>$q['type'],'options'=>$q['options'],'correct'=>$q['correct_answers']];
                $val = _seed_answer($spec, $bias);
                if ($isScored) { [$ok,$pts,$manual] = grade_answer($q, $val); }
                else { [$ok,$pts,$manual] = [null,0.0,false]; }
                if ($manual) $needsGrading = 1;
                if ($ok) $total += $pts;
                $rows[] = [$aid,$q['id'],json_encode($val),$ok===null?null:($ok?1:0),$pts,$manual?0:1];
            }
            if ($rows) DB::insertMany("INSERT INTO answers(attempt_id,question_id,answer,is_correct,points_earned,graded) VALUES(?,?,?,?,?,?)", $rows);
            $pct = $max>0 ? $total/$max*100 : 0;
            DB::run("UPDATE attempts SET score=?,max_score=?,percentage=?,needs_grading=? WHERE id=?", [$total,$max,$pct,$needsGrading,$aid]);
        }
    };

    $seedSubmissions($exam, 10, 0.7);   // exam: ~70% correctness -> nice score spread
    $seedSubmissions($poll, 18, 0.0);   // poll: random distribution
    $seedSubmissions($survey, 14, 0.6);
    $seedSubmissions($form, 7, 0.0);

    return [
        'quizzes' => 4,
        'exam_id' => $exam, 'poll_id' => $poll, 'survey_id' => $survey, 'form_id' => $form,
        'submissions' => 10 + 18 + 14 + 7,
    ];
}
