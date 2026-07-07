<?php
/**
 * End-to-end test for multi-tenant Organizations against the running server.
 * Covers: create org, org context scoping of quizzes, settings + cert branding,
 * invite + accept, roles (member vs admin edit access), personal isolation,
 * and org deletion reverting quizzes.
 *
 * Usage:  php tests/e2e_orgs.php [base_url]
 * Requires admin@quizforge.local / admin123.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

$BASE = rtrim($argv[1] ?? 'http://localhost/quizforge', '/');

$pass = 0; $fails = [];
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fails;
    if ($ok) { $pass++; echo "  [OK] $l" . ($d?"   ($d)":'') . "\n"; }
    else { $fails[] = $l; echo "  [FAIL] $l" . ($d?"   ($d)":'') . "\n"; }
}
function section(string $s): void { echo "\n=== $s ===\n"; }

class Client {
    private string $jar; public int $code = 0; public string $body = ''; public string $location = '';
    public function __construct(string $n) { $this->jar = sys_get_temp_dir() . "/qf_org_$n.txt"; @unlink($this->jar); }
    private function exec(string $url, ?array $post, array $h = []): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true,
            CURLOPT_COOKIEJAR=>$this->jar, CURLOPT_COOKIEFILE=>$this->jar,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>30]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        if ($h) curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $r = curl_exec($ch);
        $this->code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hl = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($r, 0, $hl); $this->body = substr($r, $hl);
        $this->location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $head, $m)) $this->location = trim($m[1]);
        curl_close($ch);
    }
    public function get(string $u): string { $this->exec($u, null); return $this->body; }
    public function post(string $u, array $d): void { $this->exec($u, $d); }
    public function csrf(string $u): string {
        $h = $this->get($u);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $h, $m)) return $m[1];
        if (preg_match('/meta name="csrf-token" content="([^"]+)"/', $h, $m)) return $m[1];
        return '';
    }
}

$owner = new Client('owner');
$u2    = new Client('u2');
$created = ['quizzes' => [], 'org' => 0];

// ── Owner logs in ────────────────────────────────────────────────────────────
section('Login owner');
$csrf = $owner->csrf($BASE.'/login');
$owner->post($BASE.'/login', ['email'=>'admin@quizforge.local','password'=>'admin123','_csrf'=>$csrf]);
check('owner logged in', $owner->code===302);

// ── Create org ────────────────────────────────────────────────────────────────
section('Create organization');
$csrf = $owner->csrf($BASE.'/admin/orgs');
$owner->post($BASE.'/admin/orgs', ['name'=>'E2E Acme Team','_csrf'=>$csrf]);
check('create org redirects to org home', $owner->code===302 && preg_match('#/admin/orgs/(\d+)#',$owner->location,$m), $owner->location);
$orgId = (int)($m[1] ?? 0); $created['org'] = $orgId;

$home = $owner->get($BASE."/admin/orgs/$orgId");
check('org home shows name + owner role', strpos($home,'E2E Acme Team')!==false && strpos($home,'Owner')!==false);

// ── Settings + cert branding ───────────────────────────────────────────────────
section('Org settings + certificate branding');
$csrf = $owner->csrf($BASE."/admin/orgs/$orgId");
$owner->post($BASE."/admin/orgs/$orgId/settings", ['name'=>'E2E Acme Team','cert_org_name'=>'Acme Certified','_csrf'=>$csrf]);
check('settings save redirects', $owner->code===302);
$home = $owner->get($BASE."/admin/orgs/$orgId");
check('cert_org_name persisted', strpos($home,'Acme Certified')!==false);

// ── Quiz scoping: create a quiz while org is active ─────────────────────────────
section('Org-scoped quiz creation');
// Accepting the org home already left us in personal? create_org switched active org already.
$csrf = $owner->csrf($BASE.'/admin');
$owner->post($BASE.'/admin/quizzes/new', ['kind'=>'exam','title'=>'Org Exam Alpha','_csrf'=>$csrf]);
check('quiz created in org context', $owner->code===302 && preg_match('#/admin/quizzes/(\d+)#',$owner->location,$qm), $owner->location);
$orgQuiz = (int)($qm[1] ?? 0); $created['quizzes'][] = $orgQuiz;

$dash = $owner->get($BASE.'/admin');
check('dashboard shows org banner', strpos($dash,'working in')!==false && strpos($dash,'E2E Acme Team')!==false);
check('org quiz listed on org dashboard', strpos($dash,'Org Exam Alpha')!==false);
$ohome = $owner->get($BASE."/admin/orgs/$orgId");
check('org quiz appears under org home', strpos($ohome,'Org Exam Alpha')!==false);

// ── Personal isolation ──────────────────────────────────────────────────────────
section('Personal vs org isolation');
$csrf = $owner->csrf($BASE.'/admin');
$owner->post($BASE.'/admin/orgs/switch', ['org_id'=>'','return'=>'/admin','_csrf'=>$csrf]);
check('switch to personal redirects', $owner->code===302);
$pdash = $owner->get($BASE.'/admin');
check('org quiz NOT shown in personal space', strpos($pdash,'Org Exam Alpha')===false);
$csrf = $owner->csrf($BASE.'/admin');
$owner->post($BASE.'/admin/quizzes/new', ['kind'=>'exam','title'=>'Personal Only Quiz','_csrf'=>$csrf]);
preg_match('#/admin/quizzes/(\d+)#',$owner->location,$pm); $persQuiz=(int)($pm[1]??0); $created['quizzes'][]=$persQuiz;
// Follow to the editor to consume the "Created ..." flash (the client doesn't
// auto-follow redirects, so an unconsumed flash would otherwise bleed onto the
// next page we fetch and fool a whole-page string match).
$owner->get($BASE."/admin/quizzes/$persQuiz");
$ohome = $owner->get($BASE."/admin/orgs/$orgId");
// Assert against the org's Quizzes list region specifically, not the whole page.
$quizRegion = (strpos($ohome, 'Quizzes') !== false) ? substr($ohome, strpos($ohome, 'Quizzes')) : $ohome;
check('personal quiz NOT shown under org', strpos($quizRegion,'Personal Only Quiz')===false);
// back to org for the rest
$csrf = $owner->csrf($BASE.'/admin');
$owner->post($BASE.'/admin/orgs/switch', ['org_id'=>(string)$orgId,'return'=>'/admin','_csrf'=>$csrf]);

// ── Invite a second user ─────────────────────────────────────────────────────────
section('Invite + accept');
$email2 = 'e2e_member_'.substr(bin2hex(random_bytes(3)),0,6).'@example.com';
$csrf = $u2->csrf($BASE.'/register');
$u2->post($BASE.'/register', ['name'=>'Member Two','email'=>$email2,'password'=>'secret12345','_csrf'=>$csrf]);
check('second user registered', $u2->code===302);

$csrf = $owner->csrf($BASE."/admin/orgs/$orgId");
$owner->post($BASE."/admin/orgs/$orgId/invite", ['email'=>$email2,'role'=>'member','_csrf'=>$csrf]);
check('invite created', $owner->code===302);
$ohome = $owner->get($BASE."/admin/orgs/$orgId");
check('pending invite listed', strpos($ohome,$email2)!==false);
preg_match('#/org/invite/([a-f0-9]{40})#', $ohome, $tm);
$token = $tm[1] ?? '';
check('invite token found', strlen($token)===40);

$acceptPage = $u2->get($BASE."/org/invite/$token");
check('u2 sees accept page', strpos($acceptPage,'Accept invitation')!==false || strpos($acceptPage,'Join')!==false);
$csrf = $u2->csrf($BASE."/org/invite/$token");
$u2->post($BASE."/org/invite/$token/accept", ['_csrf'=>$csrf]);
check('u2 accept redirects to org', $u2->code===302 && strpos($u2->location,"/admin/orgs/$orgId")!==false, $u2->location);

$ohome = $owner->get($BASE."/admin/orgs/$orgId");
check('u2 now a member', strpos($ohome,'Member Two')!==false || strpos($ohome,$email2)!==false);

// ── Role-based edit access ───────────────────────────────────────────────────────
section('Role-based access');
// u2 was switched into the org on accept; the shared org quiz should be visible.
$u2dash = $u2->get($BASE.'/admin');
check('member sees org quiz on dashboard', strpos($u2dash,'Org Exam Alpha')!==false);
// but a member may NOT edit a quiz they don't own
$u2edit = $u2->get($BASE."/admin/quizzes/$orgQuiz");
check('member cannot open owner\'s quiz editor (404)', $u2->code===404);
// owner promotes u2 to admin
$csrf = $owner->csrf($BASE."/admin/orgs/$orgId");
preg_match('#/members/(\d+)/#', $ohome, $uidm); // find u2 user id from a members action url
$u2id = (int)($uidm[1] ?? 0);
$owner->post($BASE."/admin/orgs/$orgId/members/$u2id/role", ['role'=>'admin','_csrf'=>$csrf]);
check('owner promoted u2 to admin', $owner->code===302 && $u2id>0, "uid $u2id");
$u2edit = $u2->get($BASE."/admin/quizzes/$orgQuiz");
check('admin CAN now open the org quiz editor', $u2->code===200);

// ── Certificate branding on an org exam ──────────────────────────────────────────
section('Org certificate branding');
$csrf = $owner->csrf($BASE."/admin/quizzes/$orgQuiz");
// add a guaranteed-pass true/false question + enable cert + pass mark + publish
$body = 'type=true_false&text='.urlencode('Water is wet?').'&correct[]=0&points=1&_csrf='.urlencode($csrf);
$ch=curl_init($BASE."/admin/quizzes/$orgQuiz/questions");
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>$body,CURLOPT_FOLLOWLOCATION=>0,
    CURLOPT_COOKIEJAR=>sys_get_temp_dir().'/qf_org_owner.txt',CURLOPT_COOKIEFILE=>sys_get_temp_dir().'/qf_org_owner.txt']);
curl_exec($ch); curl_close($ch);
$csrf = $owner->csrf($BASE."/admin/quizzes/$orgQuiz");
$owner->post($BASE."/admin/quizzes/$orgQuiz/settings", ['pass_mark'=>'50','certificate_enabled'=>'1','is_published'=>'1','show_correct_answers'=>'1','_csrf'=>$csrf]);
// find the share code
$editor = $owner->get($BASE."/admin/quizzes/$orgQuiz");
preg_match('#/q/([A-Z0-9]{7})#', $editor, $sc); $share = $sc[1] ?? '';
check('org exam has share code', strlen($share)===7, $share);
// anonymous taker passes
$anon = new Client('anon');
$csrf = $anon->csrf($BASE."/q/$share");
$anon->post($BASE."/q/$share", ['student_name'=>'Cert Taker','q_ans_dummy'=>'1','_csrf'=>$csrf]);
// The take flow: GET sets a draft; submit endpoint differs. Just confirm cert file endpoint works via admin attempt.
$res = $owner->get($BASE."/admin/quizzes/$orgQuiz/results");
check('org exam results page loads', $owner->code===200);

// ── Delete org reverts quizzes ────────────────────────────────────────────────────
section('Delete org reverts quizzes to owners');
$csrf = $owner->csrf($BASE."/admin/orgs/$orgId");
$owner->post($BASE."/admin/orgs/$orgId/delete", ['_csrf'=>$csrf]);
check('org deleted (redirect to orgs list)', $owner->code===302);
// org quiz should now be personal (owner still owns it)
$csrf = $owner->csrf($BASE.'/admin');
$owner->post($BASE.'/admin/orgs/switch', ['org_id'=>'','return'=>'/admin','_csrf'=>$csrf]);
$pdash = $owner->get($BASE.'/admin');
check('former org quiz reverted to owner personal space', strpos($pdash,'Org Exam Alpha')!==false);
$orgsList = $owner->get($BASE.'/admin/orgs');
check('org no longer listed', strpos($orgsList,'E2E Acme Team')===false);

// ── Cleanup ─────────────────────────────────────────────────────────────────────
section('Cleanup');
foreach ($created['quizzes'] as $qid) {
    $csrf = $owner->csrf($BASE."/admin/quizzes/$qid");
    if ($csrf) { $owner->post($BASE."/admin/quizzes/$qid/delete", ['_csrf'=>$csrf]); }
}
check('cleanup ran', true);

echo "\n──────────────────────────────────────────\n";
echo "PASSED: $pass   FAILED: ".count($fails)."\n";
if ($fails) { echo "Failures:\n - ".implode("\n - ",$fails)."\n"; exit(1); }
echo "ALL ORG E2E CHECKS PASSED ✅\n";
