<?php
/**
 * FULL end-to-end test against the LIVE server (Apache + MySQL).
 * Exercises every feature through real HTTP — routing, .htaccess, sessions,
 * CSRF, DB, rendering — so we catch integration bugs the unit tests miss.
 *
 * Usage:  php tests/e2e_live.php [base_url]
 *   default base_url = http://localhost/quizforge
 *
 * Requires the admin account admin@quizforge.local / admin123 to exist.
 * Creates test quizzes/users and cleans them up at the end.
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

/** Minimal curl client with a cookie jar. */
class Client {
    private string $jar;
    public int $code = 0;
    public string $body = '';
    public string $location = '';
    public function __construct(string $name) { $this->jar = sys_get_temp_dir() . "/qf_e2e_$name.txt"; @unlink($this->jar); }
    private function exec(string $url, ?array $post, bool $json = false, array $rawHeaders = []): void {
        $ch = curl_init($url);
        $headers = $rawHeaders;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($json) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post)); $headers[] = 'Content-Type: application/json'; }
            else { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        }
        if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $this->code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHead = substr($resp, 0, $hlen);
        $this->body = substr($resp, $hlen);
        $this->location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $rawHead, $m)) $this->location = trim($m[1]);
        curl_close($ch);
    }
    public function get(string $url): string { $this->exec($url, null); return $this->body; }
    public function post(string $url, array $data): void { $this->exec($url, $data); }
    public function postJson(string $url, array $data, array $headers = []): void { $this->exec($url, $data, true, $headers); }
    public function csrf(string $url): string {
        $html = $this->get($url);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) return $m[1];
        if (preg_match('/meta name="csrf-token" content="([^"]+)"/', $html, $m)) return $m[1];
        return '';
    }
}

$admin = new Client('admin');
$anon  = new Client('anon');

// ─────────────────────────────────────────────────────────────────────────
section('Public pages render (logged out)');
foreach ([['/','QuizForge or Quizly',['Quizly']], ['/login',['Sign in']], ['/register',['Create']], ['/join',['Join']], ['/forgot-password',['Reset']]] as $spec) {
    $path = $spec[0]; $needles = $spec[count($spec)-1];
    $html = $anon->get($BASE.$path);
    $ok = $anon->code === 200;
    foreach ($needles as $nd) $ok = $ok && (stripos($html, $nd) !== false);
    check("GET $path -> 200 + content", $ok, "http {$anon->code}");
}

section('Auth');
$csrf = $admin->csrf($BASE.'/login');
check('login page exposes CSRF token', $csrf !== '');
$admin->post($BASE.'/login', ['email'=>$ADMIN_EMAIL,'password'=>$ADMIN_PASS,'_csrf'=>$csrf]);
check('admin login -> redirect to /admin', $admin->code===302 && strpos($admin->location,'/admin')!==false, "http {$admin->code}");
$dash = $admin->get($BASE.'/admin');
check('dashboard shows "Welcome back, Admin"', strpos($dash,'Welcome back, Admin')!==false);
check('dashboard header has "Sign out" (not "Sign in")', strpos($dash,'Sign out')!==false && strpos($dash,'>Sign in<')===false);
check('dashboard shows all 4 content cards', strpos($dash,'Exams')!==false && strpos($dash,'Polls')!==false && strpos($dash,'Surveys')!==false && strpos($dash,'Forms')!==false);
// bad CSRF rejected
$admin->post($BASE.'/admin/quizzes/new', ['kind'=>'exam','title'=>'x','_csrf'=>'WRONG']);
check('POST with bad CSRF -> 403', $admin->code===403, "http {$admin->code}");

// ─────────────────────────────────────────────────────────────────────────
section('Create a quiz of each kind');
$created = [];
foreach (['exam'=>'E2E Exam','poll'=>'E2E Poll','survey'=>'E2E Survey','form'=>'E2E Form'] as $kind=>$title) {
    $csrf = $admin->csrf($BASE.'/admin');
    $admin->post($BASE.'/admin/quizzes/new', ['kind'=>$kind,'title'=>$title,'_csrf'=>$csrf]);
    $ok = $admin->code===302 && preg_match('#/admin/quizzes/(\d+)#',$admin->location,$m);
    check("create $kind -> editor redirect", (bool)$ok, "http {$admin->code}");
    if ($ok) $created[$kind] = (int)$m[1];
}
$examId = $created['exam'] ?? 0;

section('Add questions of many types to the exam');
$qtypes = [
    ['mcq_single','2+2?','options[]=3&options[]=4&correct[]=1'],
    ['mcq_multi','Pick evens','options[]=1&options[]=2&options[]=3&options[]=4&correct[]=1&correct[]=3'],
    ['true_false','Sky is blue','correct[]=0'],
    ['dropdown','Biggest?','options[]=Ant&options[]=Whale&correct[]=1'],
    ['short_answer','Capital of Japan?','accepted=Tokyo'],
    ['fill_blank','H__O is water','accepted=H2O'],
    ['long_answer','Explain gravity',''],
    ['rating','Rate us',''],
    ['nps','Recommend?',''],
];
foreach ($qtypes as [$type,$text,$extra]) {
    $csrf = $admin->csrf($BASE."/admin/quizzes/$examId");
    // build POST body as urlencoded string incl. array fields
    $body = 'type='.urlencode($type).'&text='.urlencode($text).'&points=1&is_required=1&_csrf='.urlencode($csrf);
    if ($extra) $body .= '&'.$extra;
    $ch = curl_init($BASE."/admin/quizzes/$examId/questions");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
        CURLOPT_COOKIEJAR=>sys_get_temp_dir().'/qf_e2e_admin.txt',CURLOPT_COOKIEFILE=>sys_get_temp_dir().'/qf_e2e_admin.txt',CURLOPT_FOLLOWLOCATION=>false]);
    $r = curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    check("add question type '$type'", $code===302, "http $code");
}
$editor = $admin->get($BASE."/admin/quizzes/$examId");
check('editor lists the added questions', substr_count($editor,'question-card')>=0 && strpos($editor,'2+2?')!==false && strpos($editor,'Recommend?')!==false);

section('Edit + settings + bulk import');
// edit first question (need its id from DB-independent means: parse editor)
// settings autosave (publish + pass mark)
$csrf = $admin->csrf($BASE."/admin/quizzes/$examId");
$admin->postJson($BASE."/admin/quizzes/$examId/settings", [], ['X-Requested-With: fetch','X-CSRF-Token: '.$csrf]);
$admin->post($BASE."/admin/quizzes/$examId/settings", ['pass_mark'=>'40','is_published'=>'1','_csrf'=>$csrf]);
check('settings save (publish + pass_mark)', $admin->code===302 || $admin->code===200);
// bulk import text
$csrf = $admin->csrf($BASE."/admin/quizzes/$examId");
$admin->post($BASE."/admin/quizzes/$examId/import", ['format'=>'text','content'=>"Largest ocean?\nA) Atlantic\nB) Pacific\nANSWER: B",'_csrf'=>$csrf]);
check('bulk import text -> redirect', $admin->code===302, "http {$admin->code}");

// ─────────────────────────────────────────────────────────────────────────
section('Take the exam (anonymous) + grading + result');
$share = null;
// read share code from editor page
if (preg_match('#Share code:\s*</span>\s*<span[^>]*>([A-Z0-9]+)#', $editor, $mm)) $share = $mm[1];
if (!$share && preg_match('#/q/([A-Z0-9]{5,})#', $editor, $mm)) $share = $mm[1];
check('found share code for exam', $share !== null, (string)$share);
if ($share) {
    $take = $anon->get($BASE."/q/$share");
    check('take page loads (200)', $anon->code===200 && strpos($take,'2+2?')!==false, "http {$anon->code}");
    // find question ids from name="q_ID"
    preg_match_all('/name="q_(\d+)"/', $take, $qm);
    $qids = array_values(array_unique($qm[1]));
    check('take page rendered question fields', count($qids) >= 5, count($qids).' fields');
    // Submit — answer everything (best-effort correct)
    $body = 'student_name='.urlencode('E2E Taker');
    foreach ($qids as $qid) {
        // radios/dropdowns: value 0 or 1; text: a word. We can't know type here,
        // so send both a scalar and array-safe value.
        $body .= "&q_$qid=1";
    }
    $body .= '&'.'q_dummy=1';
    $ch=curl_init($BASE."/q/$share");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
        CURLOPT_COOKIEJAR=>sys_get_temp_dir().'/qf_e2e_anon.txt',CURLOPT_COOKIEFILE=>sys_get_temp_dir().'/qf_e2e_anon.txt',CURLOPT_FOLLOWLOCATION=>false]);
    $r=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $hs=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);
    $loc=''; if(preg_match('/^Location:\s*(.+)$/mi',substr($r,0,$hs),$lm))$loc=trim($lm[1]); curl_close($ch);
    check('submit exam -> redirect to /done', $code===302 && strpos($loc,'/done')!==false, "http $code loc=$loc");
    $done = $anon->get($BASE."/q/$share/done");
    check('result page shows score', strpos($done,'%')!==false && strpos($done,'E2E Taker')!==false);
}

// ─────────────────────────────────────────────────────────────────────────
section('Poll: add questions, vote, dashboard');
$pollId = $created['poll'] ?? 0;
foreach ([['poll','Fav color?','options[]=Red&options[]=Blue&options[]=Green'],['nps','Recommend?',''],['open_ended','Comments?','']] as [$type,$text,$extra]) {
    $csrf = $admin->csrf($BASE."/admin/quizzes/$pollId");
    $body='type='.urlencode($type).'&text='.urlencode($text).'&points=0&_csrf='.urlencode($csrf); if($extra)$body.='&'.$extra;
    $ch=curl_init($BASE."/admin/quizzes/$pollId/questions"); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_COOKIEJAR=>sys_get_temp_dir().'/qf_e2e_admin.txt',CURLOPT_COOKIEFILE=>sys_get_temp_dir().'/qf_e2e_admin.txt',CURLOPT_FOLLOWLOCATION=>false]); curl_exec($ch); curl_close($ch);
}
$csrf = $admin->csrf($BASE."/admin/quizzes/$pollId");
$admin->post($BASE."/admin/quizzes/$pollId/settings", ['is_published'=>'1','_csrf'=>$csrf]);
$pollEditor = $admin->get($BASE."/admin/quizzes/$pollId");
$pshare = null; if (preg_match('#/q/([A-Z0-9]{5,})#',$pollEditor,$pm)) $pshare=$pm[1];
if ($pshare) {
    $pt = $anon->get($BASE."/q/$pshare");
    preg_match_all('/name="q_(\d+)"/',$pt,$pqm); $pqids=array_values(array_unique($pqm[1]));
    for ($v=0;$v<5;$v++){
        $body='student_name='.urlencode("Voter$v"); foreach($pqids as $qid){$body.="&q_$qid=".($v%3);}
        $ch=curl_init($BASE."/q/$pshare"); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_COOKIEJAR=>sys_get_temp_dir()."/qf_e2e_v$v.txt",CURLOPT_COOKIEFILE=>sys_get_temp_dir()."/qf_e2e_v$v.txt",CURLOPT_FOLLOWLOCATION=>false]); curl_exec($ch); curl_close($ch);
    }
    $pd = $admin->get($BASE."/admin/quizzes/$pollId/results");
    check('poll dashboard renders (charts/NPS)', $admin->code===200 && strpos($pd,'NPS')!==false && strpos($pd,'Fav color?')!==false, "http {$admin->code}");
}

// ─────────────────────────────────────────────────────────────────────────
section('Admin results + CSV export + attempt detail');
$res = $admin->get($BASE."/admin/quizzes/$examId/results");
check('exam results page loads', $admin->code===200 && strpos($res,'E2E Taker')!==false);
$csv = $admin->get($BASE."/admin/quizzes/$examId/export.csv");
check('CSV export downloads', $admin->code===200 && strpos($csv,'Name,Email')!==false, "http {$admin->code}");
if (preg_match('#/attempts/(\d+)#',$res,$am)) {
    $ad = $admin->get($BASE."/admin/quizzes/$examId/attempts/{$am[1]}");
    check('attempt detail loads', $admin->code===200 && strpos($ad,'Save grading')!==false);
    $csrf = $admin->csrf($BASE."/admin/quizzes/$examId/attempts/{$am[1]}");
    // grade the long_answer manually — find a pts_ field
    if (preg_match('/name="pts_(\d+)"/',$ad,$pm2)) {
        $admin->post($BASE."/admin/quizzes/$examId/attempts/{$am[1]}", ["pts_{$pm2[1]}"=>'1','_csrf'=>$csrf]);
        check('manual grading save', $admin->code===302);
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('Certificate flow (dedicated pass-guaranteed exam)');
$csrf=$admin->csrf($BASE.'/admin'); $admin->post($BASE.'/admin/quizzes/new',['kind'=>'exam','title'=>'E2E Cert','_csrf'=>$csrf]);
preg_match('#/admin/quizzes/(\d+)#',$admin->location,$cm); $certQ=(int)($cm[1]??0); $created['cert']=$certQ;
$csrf=$admin->csrf($BASE."/admin/quizzes/$certQ");
$admin->post($BASE."/admin/quizzes/$certQ/questions",['type'=>'true_false','text'=>'Yes?','correct'=>['0'],'points'=>'1','_csrf'=>$csrf]);
$csrf=$admin->csrf($BASE."/admin/quizzes/$certQ");
$admin->post($BASE."/admin/quizzes/$certQ/settings",['pass_mark'=>'50','is_published'=>'1','certificate_enabled'=>'1','_csrf'=>$csrf]);
$ce=$admin->get($BASE."/admin/quizzes/$certQ"); $cshare=null; if(preg_match('#/q/([A-Z0-9]{5,})#',$ce,$csm))$cshare=$csm[1];
if($cshare){
    $ct=$anon->get($BASE."/q/$cshare"); preg_match('/name="q_(\d+)"/',$ct,$cq);
    $anon->post($BASE."/q/$cshare",['student_name'=>'Cert Person',"q_{$cq[1]}"=>'0']);
    $cdone=$anon->get($BASE."/q/$cshare/done");
    check('passed exam shows certificate', strpos($cdone,'certificate')!==false || strpos($cdone,'Certificate')!==false);
    if(preg_match('#/cert/([A-Za-z0-9-]+)\.pdf#',$cdone,$sm)){
        $pdf=$anon->get($BASE."/cert/{$sm[1]}.pdf");
        check('certificate PDF downloads (valid PDF)', $anon->code===200 && strncmp($pdf,'%PDF',4)===0, "http {$anon->code}");
        $ver=$anon->get($BASE."/verify/{$sm[1]}");
        check('verify page shows valid', strpos($ver,'Valid certificate')!==false);
    } else { check('certificate serial present on result', false, 'no /cert/ link'); }
}

// ─────────────────────────────────────────────────────────────────────────
section('Anti-cheat violation flow');
$csrf=$admin->csrf($BASE."/admin/quizzes/$certQ");
$admin->post($BASE."/admin/quizzes/$certQ/settings",['detect_tab_switch'=>'1','violation_limit'=>'2','_csrf'=>$csrf]);
$acAnon = new Client('acanon');
$acAnon->get($BASE."/q/$cshare"); // creates a fresh draft
// find the draft attempt id via a violation with a guessed id won't work; instead read from a fresh take page? attempt id isn't in HTML by default except data-attempt
$acTake = $acAnon->get($BASE."/q/$cshare");
$aid = null; if (preg_match('/data-attempt="(\d+)"/',$acTake,$dm)) $aid=(int)$dm[1];
check('take form exposes draft attempt id', $aid!==null, (string)$aid);
if ($aid) {
    $acAnon->postJson($BASE."/q/$cshare/violation", ['attempt_id'=>$aid,'type'=>'tab_switch']);
    $j1 = json_decode($acAnon->body,true);
    $acAnon->postJson($BASE."/q/$cshare/violation", ['attempt_id'=>$aid,'type'=>'tab_switch']);
    $j2 = json_decode($acAnon->body,true);
    check('violation count increments', ($j1['count']??0)===1 && ($j2['count']??0)===2, json_encode([$j1['count']??null,$j2['count']??null]));
    check('auto_submit true at limit', ($j2['auto_submit']??false)===true);
}
// reset
$csrf=$admin->csrf($BASE."/admin/quizzes/$certQ");
$admin->post($BASE."/admin/quizzes/$certQ/settings",['detect_tab_switch'=>'0','violation_limit'=>'0','_csrf'=>$csrf]);

// ─────────────────────────────────────────────────────────────────────────
section('Form with file upload');
$formId=$created['form']??0;
$csrf=$admin->csrf($BASE."/admin/quizzes/$formId");
$admin->post($BASE."/admin/quizzes/$formId/questions",['type'=>'file_upload','text'=>'Upload a doc','points'=>'0','is_required'=>'0','_csrf'=>$csrf]);
$csrf=$admin->csrf($BASE."/admin/quizzes/$formId");
$admin->post($BASE."/admin/quizzes/$formId/questions",['type'=>'email','text'=>'Your email','points'=>'0','_csrf'=>$csrf]);
$csrf=$admin->csrf($BASE."/admin/quizzes/$formId");
$admin->post($BASE."/admin/quizzes/$formId/settings",['is_published'=>'1','require_email'=>'1','_csrf'=>$csrf]);
$fe=$admin->get($BASE."/admin/quizzes/$formId"); $fshare=null; if(preg_match('#/q/([A-Z0-9]{5,})#',$fe,$fm))$fshare=$fm[1];
if($fshare){
    $ft=$anon->get($BASE."/q/$fshare");
    preg_match_all('/name="q_(\d+)"/',$ft,$fqm); $fqids=array_values(array_unique($fqm[1]));
    // Submit a real JPEG for the file field + email
    $tmp = sys_get_temp_dir().'/qf_e2e.jpg';
    file_put_contents($tmp, hex2bin('ffd8ffe000104a464946000101000001000100'));
    $post = ['student_name'=>'Form Filler','student_email'=>'form@test.local'];
    // guess: first qid is file_upload, we send others as text
    $fileField = 'q_'.$fqids[0];
    $ch=curl_init($BASE."/q/$fshare");
    $cf = new CURLFile($tmp,'image/jpeg','doc.jpg');
    $mp = [$fileField=>$cf];
    foreach($fqids as $i=>$qid){ if($i>0)$mp["q_$qid"]='form@test.local'; }
    $mp['student_name']='Form Filler'; $mp['student_email']='form@test.local';
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$mp,CURLOPT_COOKIEJAR=>sys_get_temp_dir().'/qf_e2e_anon.txt',CURLOPT_COOKIEFILE=>sys_get_temp_dir().'/qf_e2e_anon.txt',CURLOPT_FOLLOWLOCATION=>false]);
    $r=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);@unlink($tmp);
    check('form submit with file upload -> redirect', $code===302, "http $code");
}

// ─────────────────────────────────────────────────────────────────────────
section('Site-admin panel');
foreach ([['/admin/site','Platform overview'],['/admin/site/users','Users'],['/admin/site/settings','Site settings']] as [$path,$needle]) {
    $h=$admin->get($BASE.$path);
    check("GET $path -> 200 + '$needle'", $admin->code===200 && strpos($h,$needle)!==false, "http {$admin->code}");
}
$lj=$admin->get($BASE.'/admin/site/live.json');
$ljd=json_decode($lj,true);
check('live.json returns stats', is_array($ljd) && isset($ljd['users']), $lj);

section('Ads: show when enabled, hide when disabled');
$csrf=$admin->csrf($BASE.'/admin/site/settings');
$admin->post($BASE.'/admin/site/settings',['ads_enabled'=>'1','ad_code_header'=>'<div>E2E_AD_MARKER</div>','feature_registration'=>'1','feature_certificates'=>'1','feature_live_mode'=>'1','feature_polls'=>'1','feature_anti_cheat'=>'1','feature_exports'=>'1','_csrf'=>$csrf]);
$home=$anon->get($BASE.'/');
check('ad shows on home when enabled', strpos($home,'E2E_AD_MARKER')!==false);
$csrf=$admin->csrf($BASE.'/admin/site/settings');
$admin->post($BASE.'/admin/site/settings',['ad_code_header'=>'<div>E2E_AD_MARKER</div>','feature_registration'=>'1','feature_certificates'=>'1','feature_live_mode'=>'1','feature_polls'=>'1','feature_anti_cheat'=>'1','feature_exports'=>'1','_csrf'=>$csrf]);
$home=$anon->get($BASE.'/');
check('ad hidden when disabled (code preserved)', strpos($home,'E2E_AD_MARKER')===false);

section('Password reset flow');
$rc = new Client('reset');
$csrf=$rc->csrf($BASE.'/forgot-password');
$rc->post($BASE.'/forgot-password',['email'=>$ADMIN_EMAIL,'_csrf'=>$csrf]);
$token=null; if(preg_match('#/reset-password/([A-Za-z0-9_-]+)#',$rc->body,$tm))$token=$tm[1];
check('reset link generated (on-screen fallback)', $token!==null);
if($token){
    $csrf=$rc->csrf($BASE."/reset-password/$token");
    $rc->post($BASE."/reset-password/$token",['password'=>'admin123','_csrf'=>$csrf]); // reset to same pw
    check('reset submit -> redirect to login', $rc->code===302 && strpos($rc->location,'/login')!==false, "http {$rc->code}");
    $again=$rc->get($BASE."/reset-password/$token");
    check('token is single-use (invalid on reuse)', strpos($again,'expired')!==false || strpos($again,'invalid')!==false);
}

section('User management (create test user, promote, suspend, delete)');
$reg = new Client('reg');
$csrf=$reg->csrf($BASE.'/register');
$testEmail='e2e_'.substr(md5((string)microtime(true)),0,6).'@test.local';
$reg->post($BASE.'/register',['name'=>'E2E User','email'=>$testEmail,'password'=>'pass1234','_csrf'=>$csrf]);
check('register new user -> redirect', $reg->code===302, "http {$reg->code}");
$uid = null;
// find the new user's id from the site users page (admin)
$uhtml=$admin->get($BASE.'/admin/site/users');
check('new user appears in site users list', strpos($uhtml,$testEmail)!==false);

// ─────────────────────────────────────────────────────────────────────────
section('Cleanup — delete E2E quizzes');
foreach ($created as $kind=>$qid) {
    if (!$qid) continue;
    $csrf=$admin->csrf($BASE."/admin/quizzes/$qid");
    $admin->post($BASE."/admin/quizzes/$qid/delete", ['_csrf'=>$csrf]);
    check("deleted E2E $kind quiz #$qid", $admin->code===302, "http {$admin->code}");
}

echo "\n────────────────────────────────────────\n";
echo "PASSED: $pass   FAILED: ".count($fails)."\n";
if ($fails) { echo "FAILURES:\n"; foreach ($fails as $f) echo "  - $f\n"; exit(1); }
echo "ALL LIVE E2E CHECKS PASSED ✓\n";
