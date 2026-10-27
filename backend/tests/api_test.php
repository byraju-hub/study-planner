<?php
declare(strict_types=1);

// 사용: 테스트 서버를 먼저 띄운다.
//   SP_CONFIG=backend/config.test.php php scripts/migrate.php
//   SP_CONFIG=backend/config.test.php php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8081 scripts/dev-router.php
// 그다음: php backend/tests/api_test.php [http://127.0.0.1:8081]

$base = ($argv[1] ?? 'http://127.0.0.1:8081') . '/sp/api';
$failures = 0;
$checks = 0;

function check(string $label, bool $cond, mixed $detail = null): void
{
    global $failures, $checks;
    $checks++;
    if ($cond) {
        echo "  ok   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label" . ($detail !== null ? ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

final class Client
{
    public string $csrf = '';
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'spjar');
    }

    /** @return array{0:int,1:array<string,mixed>|null,2:string} */
    public function call(string $method, string $path, ?array $json = null, array $multipart = [], bool $csrf = true): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json'];
        if ($csrf && $this->csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        } elseif ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = json_decode($body, true);
        return [$status, is_array($data) ? $data : null, $body];
    }

    /** 로그인 상태 확인 겸 CSRF 토큰 받기 */
    public function boot(): void
    {
        [, $r] = $this->call('GET', '/auth/me');
        $this->csrf = $r['data']['csrf'] ?? '';
    }

    public function signup(string $loginId, string $password = 'passw0rd123'): array
    {
        $this->boot();
        [$s, $r] = $this->call('POST', '/auth/signup', [
            'login_id' => $loginId, 'email' => "$loginId@example.com", 'password' => $password, 'name' => '테스트', 'grade' => 1,
        ]);
        if ($s !== 200) {
            throw new RuntimeException("가입 실패 $loginId: " . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        $this->csrf = $r['data']['csrf'];
        return $r['data']['user'];
    }
}

$run = substr(bin2hex(random_bytes(4)), 0, 6);

echo "[인증]\n";
$a = new Client($base);
[$s, $r] = $a->call('GET', '/auth/me');
check('비로그인 me → user null + csrf', $s === 200 && $r['data']['user'] === null && strlen($r['data']['csrf']) === 64, $r);
$a->csrf = $r['data']['csrf'];

[$s, $r] = (new Client($base))->call('POST', '/auth/signup', ['login_id' => "x$run"], [], false);
check('CSRF 없이 POST → 403', $s === 403 && ($r['error']['code'] ?? '') === 'csrf', $r);

[$s, $r] = $a->call('POST', '/auth/signup', ['login_id' => 'ab', 'email' => 'bad', 'password' => 'short', 'name' => '']);
check('잘못된 가입 값 → 422', $s === 422 && ($r['error']['code'] ?? '') === 'validation', $r);

[$s, $r] = $a->call('POST', '/auth/signup', ['login_id' => "u1$run", 'email' => "u1$run@example.com", 'password' => 'onlyletters', 'name' => '가나']);
check('숫자 없는 비밀번호 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'password', $r);

$u1 = $a->signup("u1$run");
check('가입 성공 + 자동 로그인', $u1['login_id'] === "u1$run");

[$s, $r] = $a->call('GET', '/auth/me');
check('가입 후 me → 내 정보', ($r['data']['user']['id'] ?? 0) === $u1['id']);

[$s, $r] = $a->call('GET', '/subjects');
check('기본 과목 8개 생성', $s === 200 && count($r['data']) === 8 && $r['data'][0]['name'] === '공통국어', $r);

$dup = new Client($base);
$dup->boot();
[$s, $r] = $dup->call('POST', '/auth/signup', ['login_id' => "u1$run", 'email' => "other$run@example.com", 'password' => 'passw0rd123', 'name' => '중복']);
check('아이디 중복 → 409', $s === 409 && ($r['error']['field'] ?? '') === 'login_id', $r);
[$s, $r] = $dup->call('POST', '/auth/signup', ['login_id' => "u9$run", 'email' => "u1$run@example.com", 'password' => 'passw0rd123', 'name' => '중복']);
check('이메일 중복 → 409', $s === 409 && ($r['error']['field'] ?? '') === 'email', $r);

[$s, $r] = $a->call('POST', '/auth/logout');
check('로그아웃', $s === 200 && strlen($r['data']['csrf']) === 64);
$a->csrf = $r['data']['csrf'];
[$s, $r] = $a->call('GET', '/subjects');
check('로그아웃 후 보호된 API → 401', $s === 401, $r);

[$s, $r] = $a->call('POST', '/auth/login', ['login_id' => "u1$run", 'password' => 'wrongpass1']);
check('틀린 비밀번호 → 401', $s === 401 && ($r['error']['code'] ?? '') === 'bad_credentials', $r);
[$s, $r] = $a->call('POST', '/auth/login', ['login_id' => "nobody$run", 'password' => 'wrongpass1']);
check('없는 아이디도 같은 오류', $s === 401 && ($r['error']['code'] ?? '') === 'bad_credentials', $r);

[$s, $r] = $a->call('POST', '/auth/login', ['login_id' => "U1$run", 'password' => 'passw0rd123']);
check('로그인 성공 (아이디 대소문자 무시)', $s === 200 && $r['data']['user']['login_id'] === "u1$run", $r);
$a->csrf = $r['data']['csrf'];

[$s, $r] = $a->call('PUT', '/auth/password', ['current_password' => 'nope12345', 'new_password' => 'newpass456']);
check('현재 비밀번호 틀리면 422', $s === 422, $r);
[$s, $r] = $a->call('PUT', '/auth/password', ['current_password' => 'passw0rd123', 'new_password' => 'newpass456']);
check('비밀번호 변경', $s === 200, $r);
$a->call('POST', '/auth/logout');
$a->boot();
[$s, $r] = $a->call('POST', '/auth/login', ['login_id' => "u1$run", 'password' => 'newpass456']);
check('새 비밀번호로 로그인', $s === 200, $r);
$a->csrf = $r['data']['csrf'];

$lock = new Client($base);
$lock->signup("lk$run");
$lock->call('POST', '/auth/logout');
$lock->boot();
for ($i = 0; $i < 5; $i++) {
    $lock->call('POST', '/auth/login', ['login_id' => "lk$run", 'password' => 'wrongpass1']);
}
[$s, $r] = $lock->call('POST', '/auth/login', ['login_id' => "lk$run", 'password' => 'passw0rd123']);
check('5회 실패 후 올바른 비밀번호도 429', $s === 429 && ($r['error']['code'] ?? '') === 'too_many_attempts', $r);

[$s, $r] = $a->call('GET', '/nope');
check('없는 주소 → 404', $s === 404, $r);

echo "[과목]\n";
[$s, $r] = $a->call('POST', '/subjects', ['name' => '논술', 'color' => '#336699']);
check('과목 추가', $s === 200 && $r['data']['name'] === '논술', $r);
$subId = $r['data']['id'] ?? 0;
[$s, $r] = $a->call('POST', '/subjects', ['name' => '논술']);
check('과목 이름 중복 → 409', $s === 409, $r);
[$s, $r] = $a->call('POST', '/subjects', ['name' => '색상', 'color' => 'red']);
check('색상 형식 오류 → 422', $s === 422, $r);
[$s, $r] = $a->call('PUT', "/subjects/$subId", ['name' => '논술(수정)', 'color' => '#112233']);
check('과목 수정', $s === 200 && $r['data']['name'] === '논술(수정)' && $r['data']['color'] === '#112233', $r);

$b = new Client($base);
$b->signup("u2$run");
[$s, $r] = $b->call('PUT', "/subjects/$subId", ['name' => '해킹']);
check('남의 과목 수정 → 404', $s === 404, $r);
[$s, $r] = $b->call('DELETE', "/subjects/$subId");
check('남의 과목 삭제 → 404', $s === 404, $r);

[$s, $r] = $a->call('DELETE', "/subjects/$subId");
check('과목 삭제', $s === 200, $r);

echo "\n" . ($failures === 0 ? "전체 통과 ($checks)" : "실패 $failures / $checks") . "\n";
exit($failures === 0 ? 0 : 1);
