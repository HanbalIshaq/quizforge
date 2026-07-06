<?php
/**
 * End-to-end test for LIVE mode (Kahoot-style) against the running server.
 * Exercises: start session, two participants join, host advances through
 * questions, players answer (right/wrong), scoring + leaderboard, and end.
 *
 * Usage:  php tests/e2e_livemode.php [base_url]
 * Requires admin@quizforge.local / admin123.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$BASE = rtrim($argv[1] ?? 'http://localhost/quizforge', '/');
$ADMIN_EMAIL = 'admin@quizforge.local';
$ADMIN_PASS  = 'admin123';

$pass = 0; $fails = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fails;
    if ($ok) { $pass++; echo "  [OK] $label" . ($detail?"   ($detail)":'') . "\n"; }
    else { $fails[] = $label; echo "  [FAIL] $label" . ($detail?"   ($detail)":'') . "\n"; }
}
function section(string $s): void { echo "\n=== $s ===\n"; }

class Client {
    private string $jar; public int $code = 0; public string $body = ''; public string $location = '';
    public function __construct(string $name) { $this->jar = sys_get_temp_dir() . "/qf_lm_$name.txt"; @unlink($this->jar); }
    private function exec(string $url, ?array $post, array $rawHeaders = [], ?string $rawBody = null): void {
        $ch = curl_init($url); $headers = $rawHeaders;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true,
            CURLOPT_COOKIEJAR=>$this->jar, CURLOPT_COOKIEFILE=>$this->jar,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>30]);
        if ($rawBody !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody); }
        elseif ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $this->code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHead = substr($resp, 0, $hlen); $this->body = substr($resp, $hlen);
        $this->location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $rawHead, $m)) $this->location = trim($m[1]);
        curl_close($ch);
    }
    public function get(string $url): string { $this->exec($url, null); return $this->body; }
    public function post(string $url, array $data): void { $this->exec($url, $data); }
    public function postRaw(string $url, string $body, array $headers = []): void { $this->exec($url, null, $headers, $body); }
    public function json(string $url) { return json_decode($this->get($url), true); }
    public function csrf(string $url): string {
        $html = $this->get($url);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) return $m[1];
        if (preg_match('/meta name="csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
        return '';
    }
}

$admin = new Client('admin');
$p1 = new Client('p1');   // participant Alice (answers correctly)
$p2 = new Client('p2');   // participant Bob (answers wrongly)

// ── Log in ────────────────────────────────────────────────────────────────
section('Login');
$csrf = $admin->csrf($BASE.'/login');
$admin->post($BASE.'/login', ['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PASS,'_csrf'=>$csrf]);
check('admin logged in', $admin->code===302, "http {$admin->code}");

// ── Build a small exam with 3 auto-graded choice questions ──────────────────
section('Create quiz + questions');
$csrf = $admin->csrf($BASE.'/admin');
$admin->post($BASE.'/admin/quizzes/new', ['kind'=>'exam','title'=>'E2E Live','_csrf'=>$csrf]);
preg_match('#/admin/quizzes/(\d+)#', $admin->location, $m);
$qid = (int)($m[1] ?? 0);
check('quiz created', $qid>0, "id $qid");

$questions = [
    ['mcq_single','2+2?','options[]=3&options[]=4&options[]=5&correct[]=1'],   // correct index 1
    ['true_false','The sky is blue','correct[]=0'],                            // correct index 0 (True)
    ['mcq_single','Capital of France?','options[]=Rome&options[]=Paris&correct[]=1'], // correct index 1
];
foreach ($questions as [$type,$text,$extra]) {
    $csrf = $admin->csrf($BASE."/admin/quizzes/$qid");
    $body = 'type='.urlencode($type).'&text='.urlencode($text).'&points=1&_csrf='.urlencode($csrf);
    if ($extra) $body .= '&'.$extra;
    $admin->postRaw($BASE."/admin/quizzes/$qid/questions", $body);
    check("added $type", $admin->code===302, "http {$admin->code}");
}

// ── Start a live session ────────────────────────────────────────────────────
section('Start live session');
$csrf = $admin->csrf($BASE."/admin/quizzes/$qid");
$admin->postRaw($BASE."/admin/quizzes/$qid/live", 'live=1&_csrf='.urlencode($csrf));
check('go-live redirects to host room', $admin->code===302 && preg_match('#/admin/live/(\d+)#',$admin->location,$sm), $admin->location);
$sid = (int)($sm[1] ?? 0);

$host = $admin->get($BASE."/admin/live/$sid");
check('host room renders + shows PIN', strpos($host,'Game PIN')!==false, "http {$admin->code}");
preg_match('/tracking-widest">([A-Z0-9]{6})</', $host, $cm);
$code = $cm[1] ?? '';
check('join code parsed from host page', strlen($code)===6, $code);

$hs = $admin->json($BASE."/admin/live/$sid/host.json");
check('host.json status=waiting', ($hs['status']??'')==='waiting');
check('host.json total=3', ($hs['total']??0)===3, 'total '.($hs['total']??'?'));

// ── Participants join ───────────────────────────────────────────────────────
section('Participants join');
$landing = $p1->get($BASE."/live/$code");
check('p1 sees name form', strpos($landing,'nickname')!==false, "http {$p1->code}");
$p1->post($BASE."/live/$code/join", ['name'=>'Alice']);
check('p1 joined -> play', $p1->code===302 && strpos($p1->location,'/live/play/')!==false, $p1->location);
$p2->get($BASE."/live/$code");
$p2->post($BASE."/live/$code/join", ['name'=>'Bob']);
check('p2 joined -> play', $p2->code===302 && strpos($p2->location,'/live/play/')!==false, $p2->location);

$hs = $admin->json($BASE."/admin/live/$sid/host.json");
check('host sees 2 players', ($hs['players']??0)===2, 'players '.($hs['players']??'?'));
check('roster lists Alice & Bob', in_array('Alice',$hs['roster']??[]) && in_array('Bob',$hs['roster']??[]));

$ps = $p1->json($BASE."/live/play/$sid/state.json");
check('p1 state=waiting (lobby)', ($ps['status']??'')==='waiting');

// ── Play through the 3 questions ────────────────────────────────────────────
$answerKey = [1, 0, 1]; // correct index per question
foreach ([0,1,2] as $qi) {
    section("Question ".($qi+1));
    // Host advances
    $admin->postRaw($BASE."/admin/live/$sid/next", '', ['X-CSRF-Token: '.$csrf]);
    $adv = json_decode($admin->body, true);
    check('host advanced', ($adv['ok']??false)===true && ($adv['index']??-1)===$qi, 'index '.($adv['index']??'?'));

    // Players fetch the question
    $ps1 = $p1->json($BASE."/live/play/$sid/state.json");
    check('p1 gets running question', ($ps1['status']??'')==='running' && isset($ps1['question']), "n=".($ps1['question']['n']??'?'));
    check('question has no correct answer leaked', !isset($ps1['question']['correct']));

    // Alice answers correctly, Bob answers wrong (index 2, or 1 for T/F)
    $correct = $answerKey[$qi];
    $wrong = ($correct===0) ? 1 : 0;
    $p1->postRaw($BASE."/live/play/$sid/answer", 'answer='.urlencode(json_encode($correct)));
    $r1 = json_decode($p1->body, true);
    check('Alice correct +1000', ($r1['ok']??false) && ($r1['correct']??false)===true && ($r1['award']??0)===1000);
    $p2->postRaw($BASE."/live/play/$sid/answer", 'answer='.urlencode(json_encode($wrong)));
    $r2 = json_decode($p2->body, true);
    check('Bob wrong +0', ($r2['ok']??false) && ($r2['correct']??true)===false && ($r2['award']??-1)===0);

    // Double-answer rejected
    $p1->postRaw($BASE."/live/play/$sid/answer", 'answer='.urlencode(json_encode($correct)));
    $rdup = json_decode($p1->body, true);
    check('duplicate answer rejected', ($rdup['ok']??true)===false && ($rdup['reason']??'')==='already');

    // Host sees answered count + distribution
    $hs = $admin->json($BASE."/admin/live/$sid/host.json");
    check('host answered=2', ($hs['answered']??0)===2, 'answered '.($hs['answered']??'?'));
    check('distribution recorded', array_sum($hs['distribution']??[])>=2, json_encode($hs['distribution']??[]));
}

// ── Finish ──────────────────────────────────────────────────────────────────
section('Finish + leaderboard');
$admin->postRaw($BASE."/admin/live/$sid/next", '', ['X-CSRF-Token: '.$csrf]); // moves past last -> ended
$hs = $admin->json($BASE."/admin/live/$sid/host.json");
check('session ended', ($hs['status']??'')==='ended', $hs['status']??'?');
$board = $hs['leaderboard'] ?? [];
check('Alice tops leaderboard with 3000', ($board[0]['name']??'')==='Alice' && (int)($board[0]['score']??0)===3000, json_encode($board));
check('Bob has 0', ($board[1]['name']??'')==='Bob' && (int)($board[1]['score']??0)===0);

$ps1 = $p1->json($BASE."/live/play/$sid/state.json");
check('p1 final state ended, score 3000', ($ps1['status']??'')==='ended' && (int)($ps1['you']['score']??0)===3000);

// ── Cleanup ─────────────────────────────────────────────────────────────────
section('Cleanup');
$csrf = $admin->csrf($BASE."/admin/quizzes/$qid");
$admin->post($BASE."/admin/quizzes/$qid/delete", ['_csrf'=>$csrf]);
check('quiz deleted', $admin->code===302);

echo "\n──────────────────────────────────────────\n";
echo "PASSED: $pass   FAILED: ".count($fails)."\n";
if ($fails) { echo "Failures:\n - ".implode("\n - ",$fails)."\n"; exit(1); }
echo "ALL LIVE-MODE CHECKS PASSED ✅\n";
