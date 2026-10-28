<?php
declare(strict_types=1);

// 사용: 테스트 서버를 먼저 띄운다.
//   SP_CONFIG=backend/config.test.php php scripts/migrate.php
//   SP_CONFIG=backend/config.test.php php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8081 scripts/dev-router.php
// 그다음: php backend/tests/api_test.php [http://127.0.0.1:8081]

// 서버가 한국 시간(Asia/Seoul) 기준으로 날짜를 계산하므로 테스트도 같은 시간대를 쓴다.
date_default_timezone_set('Asia/Seoul');
$base = ($argv[1] ?? 'http://127.0.0.1:8081') . '/sp/api';

// 로그인 실패 제한(IP·아이디별)이 여러 번 돌린 테스트의 기록 때문에 걸리지 않도록, 테스트 DB의 기록을 비운다.
$testConfig = __DIR__ . '/../config.test.php';
if (is_file($testConfig)) {
    $db = (require $testConfig)['db'];
    if (str_ends_with($db['name'], '_test')) {
        $pdo = new PDO("mysql:host={$db['host']};port=" . ($db['port'] ?? 3306) . ";dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass']);
        $pdo->exec('DELETE FROM sp_login_attempts');
    }
}
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
    public string $lastType = '';
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
        $this->lastType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
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

final class ReviewControllerDate
{
    public static function plus(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify("$days days")->format('Y-m-d');
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

echo "[교재]\n";
[, $r] = $a->call('GET', '/subjects');
$mathId = 0;
foreach ($r['data'] as $row) {
    if ($row['name'] === '공통수학') {
        $mathId = $row['id'];
    }
}
[$s, $r] = $a->call('POST', '/textbooks', ['subject_id' => $mathId, 'title' => '공통수학1 교과서', 'kind' => 'textbook', 'publisher' => '테스트출판', 'total_pages' => 300]);
check('교재 추가', $s === 200 && $r['data']['title'] === '공통수학1 교과서', $r);
$tbId = $r['data']['id'] ?? 0;
[$s, $r] = $a->call('POST', '/textbooks', ['subject_id' => $mathId, 'title' => '문제집', 'kind' => 'bogus']);
check('교재 종류 오류 → 422', $s === 422, $r);
[$s, $r] = $a->call('GET', "/textbooks?subject_id=$mathId");
check('교재 목록', $s === 200 && count($r['data']) === 1 && $r['data'][0]['unit_count'] === 0, $r);
[$s, $r] = $a->call('PUT', "/textbooks/$tbId", ['title' => '공통수학1 (수정)', 'publisher' => null]);
check('교재 수정 + 출판사 비우기', $s === 200 && $r['data']['publisher'] === null && $r['data']['total_pages'] === 300, $r);
[$s, $r] = $b->call('GET', "/textbooks?subject_id=$mathId");
check('남의 과목의 교재 목록 → 404', $s === 404, $r);
[$s, $r] = $b->call('PUT', "/textbooks/$tbId", ['title' => '해킹']);
check('남의 교재 수정 → 404', $s === 404, $r);

echo "[단원]\n";
[$s, $r] = $a->call('POST', '/units', ['textbook_id' => $tbId, 'title' => '1. 다항식', 'page_start' => 8, 'page_end' => 41]);
check('대단원 추가', $s === 200 && $r['data']['parent_id'] === null && $r['data']['sort_order'] === 1, $r);
$bigId = $r['data']['id'] ?? 0;
[$s, $r] = $a->call('POST', '/units', ['textbook_id' => $tbId, 'parent_id' => $bigId, 'title' => '1-1 다항식의 연산', 'page_start' => 8, 'page_end' => 25]);
check('중단원 추가', $s === 200 && $r['data']['parent_id'] === $bigId, $r);
$smallId = $r['data']['id'] ?? 0;
[$s, $r] = $a->call('POST', '/units', ['textbook_id' => $tbId, 'parent_id' => $smallId, 'title' => '3단계']);
check('중단원 아래 단원 → 422', $s === 422, $r);
[$s, $r] = $a->call('POST', '/units', ['textbook_id' => $tbId, 'title' => '쪽수 오류', 'page_start' => 30, 'page_end' => 10]);
check('끝 쪽 < 시작 쪽 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'page_end', $r);

[$s, $r] = $a->call('POST', '/textbooks', ['subject_id' => $mathId, 'title' => '다른 교재']);
$tb2 = $r['data']['id'];
[$s, $r] = $a->call('POST', '/units', ['textbook_id' => $tb2, 'parent_id' => $bigId, 'title' => '다른 교재의 대단원 사용']);
check('다른 교재의 대단원을 부모로 → 422', $s === 422, $r);

[$s, $r] = $a->call('GET', "/units?textbook_id=$tbId");
check('단원 목록 2개', $s === 200 && count($r['data']) === 2, $r);
[$s, $r] = $a->call('PUT', "/units/$smallId", ['title' => '1-1 (수정)', 'page_start' => 9, 'page_end' => 24]);
check('단원 수정', $s === 200 && $r['data']['title'] === '1-1 (수정)' && $r['data']['page_start'] === 9, $r);
[$s, $r] = $b->call('POST', '/units', ['textbook_id' => $tbId, 'title' => '남의 교재']);
check('남의 교재에 단원 추가 → 404', $s === 404, $r);

echo "[자료]\n";
[$s, $r] = $a->call('POST', '/materials', ['unit_id' => $smallId, 'type' => 'note', 'title' => '곱셈 공식', 'content' => "(a+b)^2 = a^2 + 2ab + b^2\n줄바꿈 확인"]);
check('메모 추가 (줄바꿈 보존)', $s === 200 && str_contains($r['data']['content'], "\n"), $r);
$noteId = $r['data']['id'] ?? 0;
[$s, $r] = $a->call('POST', '/materials', ['unit_id' => $smallId, 'type' => 'note', 'title' => '빈 메모', 'content' => '  ']);
check('빈 메모 → 422', $s === 422, $r);
[$s, $r] = $a->call('POST', '/materials', ['unit_id' => $smallId, 'type' => 'link', 'title' => '강의', 'url' => 'https://example.com/a?b=1']);
check('링크 추가', $s === 200 && $r['data']['url'] === 'https://example.com/a?b=1', $r);
[$s, $r] = $a->call('POST', '/materials', ['unit_id' => $smallId, 'type' => 'link', 'title' => '나쁜 링크', 'url' => 'javascript:alert(1)']);
check('javascript: 링크 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'url', $r);
[$s, $r] = $a->call('PUT', "/materials/$noteId", ['title' => '곱셈 공식(수정)', 'content' => '수정된 내용']);
check('메모 수정', $s === 200 && $r['data']['content'] === '수정된 내용', $r);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmpPng = tempnam(sys_get_temp_dir(), 'png');
file_put_contents($tmpPng, $png);
$tmpTxt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($tmpTxt, 'not an image');

[$s, $r] = $a->call('POST', '/materials/upload', null, ['unit_id' => $smallId, 'title' => '필기 사진', 'file' => new CURLFile($tmpPng, 'image/png', '필기.png')]);
check('PNG 업로드', $s === 200 && $r['data']['type'] === 'file' && $r['data']['file_name'] === '필기.png' && !isset($r['data']['file_path']), $r);
$fileId = $r['data']['id'] ?? 0;
[$s, $r] = $a->call('POST', '/materials/upload', null, ['unit_id' => $smallId, 'file' => new CURLFile($tmpTxt, 'text/plain', 'fake.png')]);
check('내용이 이미지가 아닌 .png → 422', $s === 422, $r);
[$s, $r] = $a->call('POST', '/materials/upload', null, ['unit_id' => $smallId, 'file' => new CURLFile($tmpTxt, 'text/plain', 'run.php')]);
check('허용되지 않은 확장자 → 422', $s === 422, $r);
[$s, $r] = $a->call('POST', '/materials/upload', null, ['unit_id' => $smallId]);
check('파일 없음 → 422', $s === 422, $r);

[$s, , $raw] = $a->call('GET', "/materials/$fileId/file");
check('내 파일 내려받기', $s === 200 && $raw === $png && str_starts_with($a->lastType, 'image/png'), [$s, $a->lastType]);
[$s, $r] = $b->call('GET', "/materials/$fileId/file");
check('남의 파일 → 404', $s === 404, $r);
[$s, $r] = $b->call('GET', "/materials?unit_id=$smallId");
check('남의 단원의 자료 목록 → 404', $s === 404, $r);

$uploadRoot = sys_get_temp_dir() . '/sp_test_uploads';
$countFiles = static fn(): int => count(glob($uploadRoot . '/' . $u1['id'] . '/*') ?: []);
check('업로드 파일이 디스크에 있음', $countFiles() === 1, $countFiles());
[$s, $r] = $a->call('DELETE', "/materials/$fileId");
check('자료 삭제 시 파일도 삭제', $s === 200 && $countFiles() === 0, $countFiles());

[$s, $r] = $a->call('POST', '/materials/upload', null, ['unit_id' => $smallId, 'file' => new CURLFile($tmpPng, 'image/png', 'again.png')]);
check('다시 업로드', $s === 200, $r);
[$s, $r] = $a->call('DELETE', "/units/$bigId");
check('대단원 삭제 → 하위 단원·자료·파일 삭제', $s === 200 && $countFiles() === 0, $countFiles());
[$s, $r] = $a->call('GET', "/units?textbook_id=$tbId");
check('삭제 후 단원 없음', $s === 200 && count($r['data']) === 0, $r);

[$s, $r] = $a->call('POST', '/units', ['textbook_id' => $tbId, 'title' => '1. 다시']);
$u3 = $r['data']['id'];
$a->call('POST', '/materials/upload', null, ['unit_id' => $u3, 'file' => new CURLFile($tmpPng, 'image/png', 'z.png')]);
check('교재 삭제 전 파일 1개', $countFiles() === 1, $countFiles());
[$s, $r] = $a->call('DELETE', "/subjects/$mathId");
check('과목 삭제 → 교재·파일 삭제', $s === 200 && $countFiles() === 0, $countFiles());
[$s, $r] = $a->call('GET', "/textbooks?subject_id=$mathId");
check('삭제된 과목 → 404', $s === 404, $r);
unlink($tmpPng);
unlink($tmpTxt);

echo "[할 일]\n";
$c = new Client($base);
$c->signup("u3$run");
[, $r] = $c->call('GET', '/subjects');
$sid = array_column($r['data'], 'id', 'name');
$today = date('Y-m-d');

[$s, $r] = $c->call('POST', '/tasks', ['title' => '수학 p.26–33', 'subject_id' => $sid['공통수학'], 'est_minutes' => 30]);
check('할 일 추가 (날짜 기본값 = 오늘)', $s === 200 && $r['data']['task_date'] === $today && $r['data']['subject_name'] === '공통수학' && $r['data']['done'] === false, $r);
$t1 = $r['data']['id'] ?? 0;
[$s, $r] = $c->call('POST', '/tasks', ['title' => '날짜 오류', 'task_date' => '2026-13-45']);
check('잘못된 날짜 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'task_date', $r);
[$s, $r] = $c->call('POST', '/tasks', ['title' => '남의 과목', 'subject_id' => $subId]);
check('없는/남의 과목 → 404', $s === 404, $r);
[$s, $r] = $c->call('POST', '/tasks', ['title' => '영단어', 'subject_id' => $sid['공통영어']]);
$t2 = $r['data']['id'] ?? 0;
[$s, $r] = $c->call('GET', '/tasks');
check('오늘 할 일 목록 2개', $s === 200 && count($r['data']) === 2, $r);
[$s, $r] = $c->call('POST', "/tasks/$t1/toggle");
check('완료 토글', $s === 200 && $r['data']['done'] === true, $r);
[$s, $r] = $c->call('GET', '/tasks');
check('완료한 할 일은 뒤로 정렬', $r['data'][0]['id'] === $t2 && $r['data'][1]['id'] === $t1, $r);
[$s, $r] = $c->call('POST', "/tasks/$t1/toggle", ['done' => false]);
check('완료 취소', $r['data']['done'] === false, $r);
[$s, $r] = $c->call('PUT', "/tasks/$t2", ['title' => '영단어 Day 12', 'task_date' => ReviewControllerDate::plus($today, 1), 'est_minutes' => null]);
check('할 일 수정 (날짜 이동, 시간 비우기)', $s === 200 && $r['data']['title'] === '영단어 Day 12' && $r['data']['est_minutes'] === null, $r);
[$s, $r] = $c->call('GET', '/tasks?from=' . $today . '&to=' . ReviewControllerDate::plus($today, 1));
check('기간 조회 2개', count($r['data']) === 2, $r);
[$s, $r] = $c->call('GET', '/tasks?from=2026-01-01&to=2026-12-31');
check('62일 초과 기간 → 422', $s === 422, $r);
[$s, $r] = $b->call('POST', "/tasks/$t1/toggle");
check('남의 할 일 토글 → 404', $s === 404, $r);
[$s, $r] = $b->call('DELETE', "/tasks/$t1");
check('남의 할 일 삭제 → 404', $s === 404, $r);
[$s, $r] = $c->call('DELETE', "/tasks/$t2");
check('할 일 삭제', $s === 200, $r);

echo "[시간표 · 당일 복습]\n";
$monday = date('Y-m-d', strtotime('last monday'));
$tuesday = ReviewControllerDate::plus($monday, 1);
[$s, $r] = $c->call('PUT', '/timetable', ['slots' => [
    ['weekday' => 1, 'period' => 1, 'subject_id' => $sid['공통국어']],
    ['weekday' => 1, 'period' => 2, 'subject_id' => $sid['공통수학']],
    ['weekday' => 2, 'period' => 1, 'subject_id' => null],
]]);
check('시간표 저장 (빈 칸 제외)', $s === 200 && count($r['data']['slots']) === 2, $r);
[$s, $r] = $c->call('PUT', '/timetable', ['slots' => [['weekday' => 6, 'period' => 1, 'subject_id' => $sid['공통국어']]]]);
check('토요일 시간표 → 422', $s === 422, $r);
[$s, $r] = $c->call('PUT', '/timetable', ['slots' => [['weekday' => 1, 'period' => 1, 'subject_id' => $subId]]]);
check('남의 과목으로 시간표 → 404', $s === 404, $r);
[$s, $r] = $c->call('GET', '/timetable');
check('실패한 저장은 시간표를 바꾸지 않음', count($r['data']['slots']) === 2, $r);

[$s, $r] = $c->call('GET', "/study/today?date=$monday");
check('월요일 오늘 수업 2개', $s === 200 && count($r['data']['lessons']) === 2 && $r['data']['lessons'][1]['subject_name'] === '공통수학' && $r['data']['lessons'][0]['done'] === false, $r);
[$s, $r] = $c->call('GET', '/study/today?date=' . ReviewControllerDate::plus($monday, 5));
check('토요일은 수업 없음', $r['data']['lessons'] === [], $r);

[$s, $r] = $c->call('PUT', '/study/lessons', ['date' => $monday, 'period' => 5, 'done' => true]);
check('시간표에 없는 교시 → 422', $s === 422, $r);
[$s, $r] = $c->call('PUT', '/study/lessons', ['date' => $monday, 'period' => 2, 'topic' => '나머지정리', 'done' => true]);
check('수업 복습 완료 → 1차 복습 등록', $s === 200 && $r['data']['done'] === true && $r['data']['review_item_id'] > 0, $r);
$reviewId = $r['data']['review_item_id'] ?? 0;
[$s, $r] = $c->call('GET', '/reviews?scope=due');
$mine = array_values(array_filter($r['data'], fn($x) => $x['id'] === $reviewId));
check('다음 날(화) 복습이 기한 도래 목록에 있음', count($mine) === 1 && $mine[0]['next_review_date'] === $tuesday && $mine[0]['stage'] === 1 && $mine[0]['interval_days'] === 1 && $mine[0]['title'] === '나머지정리' && $mine[0]['overdue_days'] >= 0, $r);

[$s, $r] = $c->call('PUT', '/study/lessons', ['date' => $monday, 'period' => 2, 'topic' => '나머지정리', 'done' => true]);
[$s, $r] = $c->call('GET', '/reviews?scope=due');
check('같은 수업을 다시 저장해도 복습이 중복되지 않음', count(array_filter($r['data'], fn($x) => $x['title'] === '나머지정리')) === 1, $r);

[$s, $r] = $c->call('PUT', '/study/lessons', ['date' => $monday, 'period' => 2, 'done' => false]);
check('완료 취소', $s === 200 && $r['data']['done'] === false && $r['data']['review_item_id'] === null, $r);
[$s, $r] = $c->call('GET', '/reviews?scope=due');
check('복습한 적 없는 항목은 함께 삭제', count(array_filter($r['data'], fn($x) => $x['id'] === $reviewId)) === 0, $r);

echo "[복습 주기]\n";
[$s, $r] = $c->call('POST', '/reviews', ['title' => '이차방정식', 'subject_id' => $sid['공통수학']]);
check('복습 직접 추가 (내일 1차)', $s === 200 && $r['data']['next_review_date'] === ReviewControllerDate::plus($today, 1) && $r['data']['stage'] === 1, $r);
$rv = $r['data']['id'] ?? 0;
$expected = [[2, 3], [3, 7], [4, 14], [5, 30]];
foreach ($expected as [$stage, $days]) {
    [$s, $r] = $c->call('POST', "/reviews/$rv/done");
    check("복습 완료 → {$stage}차, {$days}일 뒤", $s === 200 && $r['data']['stage'] === $stage && $r['data']['next_review_date'] === ReviewControllerDate::plus($today, $days) && $r['data']['finished'] === false, $r);
}
[$s, $r] = $c->call('POST', "/reviews/$rv/done", ['hard' => true]);
check('어려웠어요 → 단계 유지, 내일 다시', $r['data']['stage'] === 5 && $r['data']['next_review_date'] === ReviewControllerDate::plus($today, 1), $r);
[$s, $r] = $c->call('POST', "/reviews/$rv/done");
check('5차까지 마치면 종료', $s === 200 && $r['data']['finished'] === true && $r['data']['next_review_date'] === null, $r);
[$s, $r] = $c->call('POST', "/reviews/$rv/done");
check('종료된 항목 다시 완료 → 409', $s === 409, $r);
[$s, $r] = $c->call('GET', '/reviews?scope=upcoming');
check('종료된 항목은 예정 목록에 없음', count(array_filter($r['data'], fn($x) => $x['id'] === $rv)) === 0, $r);
[$s, $r] = $c->call('POST', '/reviews', ['title' => '지울 복습']);
$rv2 = $r['data']['id'];
[$s, $r] = $b->call('POST', "/reviews/$rv2/done");
check('남의 복습 완료 → 404', $s === 404, $r);
[$s, $r] = $c->call('DELETE', "/reviews/$rv2");
check('복습 삭제', $s === 200, $r);

echo "[공부 타이머 · 회고]\n";
[$s, $r] = $c->call('GET', '/sessions/active');
check('진행 중인 타이머 없음', $s === 200 && $r['data']['session'] === null && !empty($r['data']['server_now']), $r);
[$s, $r] = $c->call('POST', '/sessions', ['subject_id' => $sid['공통수학']]);
check('타이머 시작', $s === 200 && $r['data']['session']['subject_name'] === '공통수학' && $r['data']['session']['ended_at'] === null, $r);
$ses = $r['data']['session']['id'] ?? 0;
[$s, $r] = $c->call('POST', '/sessions', []);
check('이미 진행 중이면 409', $s === 409, $r);
[$s, $r] = $c->call('GET', '/study/today');
check('오늘 화면에 진행 중 타이머 포함', ($r['data']['session']['id'] ?? 0) === $ses, $r);
[$s, $r] = $b->call('POST', "/sessions/$ses/stop");
check('남의 타이머 정지 → 404', $s === 404, $r);
[$s, $r] = $c->call('POST', "/sessions/$ses/stop");
check('타이머 정지', $s === 200 && $r['data']['session']['ended_at'] !== null && $r['data']['session']['minutes'] === 0, $r);
[$s, $r] = $c->call('POST', "/sessions/$ses/stop");
check('이미 정지한 타이머 → 409', $s === 409, $r);
[$s, $r] = $c->call('GET', '/stats/week');
check('주간 통계 7일', $s === 200 && count($r['data']['days']) === 7 && $r['data']['days'][6]['date'] === $today, $r);

[$s, $r] = $c->call('PUT', '/journal', ['date' => $today, 'good' => "수학 끝냄\n줄바꿈", 'bad' => '영어 못함', 'self_rating' => 4]);
check('하루 회고 저장', $s === 200 && $r['data']['journal']['self_rating'] === 4, $r);
[$s, $r] = $c->call('PUT', '/journal', ['date' => $today, 'good' => '수정', 'self_rating' => 9]);
check('자기 평가 범위 오류 → 422', $s === 422, $r);
[$s, $r] = $c->call('PUT', '/journal', ['date' => $today, 'good' => '수정됨', 'bad' => null, 'self_rating' => 5]);
check('회고 덮어쓰기', $r['data']['journal']['good'] === '수정됨' && $r['data']['journal']['bad'] === null, $r);
[$s, $r] = $c->call('GET', '/study/today');
check('오늘 화면에 회고 포함', ($r['data']['journal']['self_rating'] ?? 0) === 5, $r);
[$s, $r] = $b->call('GET', '/journal?date=' . $today);
check('남의 회고는 보이지 않음', $r['data']['journal'] === null, $r);

echo "[시험]\n";
$d14 = ReviewControllerDate::plus($today, 14);
$d15 = ReviewControllerDate::plus($today, 15);
$d17 = ReviewControllerDate::plus($today, 17);
[$s, $r] = $c->call('POST', '/exams', ['type' => 'midterm', 'title' => '2학기 중간고사', 'start_date' => $d14, 'end_date' => $d17]);
check('시험 등록 (D-14, 예정)', $s === 200 && $r['data']['d_day'] === 14 && $r['data']['status'] === 'upcoming' && $r['data']['subjects'] === [], $r);
$exId = $r['data']['id'] ?? 0;
[$s, $r] = $c->call('POST', '/exams', ['type' => 'final', 'title' => '날짜 거꾸로', 'start_date' => $d17, 'end_date' => $d14]);
check('종료일이 시작일보다 앞 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'end_date', $r);
[$s, $r] = $c->call('POST', '/exams', ['type' => 'quiz', 'title' => '종류 오류', 'start_date' => $d14]);
check('시험 종류 오류 → 422', $s === 422, $r);
[$s, $r] = $c->call('POST', '/exams', ['type' => 'mock', 'title' => '10월 모의고사', 'start_date' => ReviewControllerDate::plus($today, -10)]);
check('모의고사는 종료일 생략 가능 → 하루짜리, 지난 시험은 완료', $s === 200 && $r['data']['end_date'] === $r['data']['start_date'] && $r['data']['status'] === 'done' && $r['data']['d_day'] === -10, $r);
$mockId = $r['data']['id'] ?? 0;
[$s, $r] = $c->call('GET', '/exams');
check('목록: 다가오는 시험이 먼저, 지난 시험은 뒤', $s === 200 && count($r['data']) === 2 && $r['data'][0]['id'] === $exId && $r['data'][1]['id'] === $mockId, $r);
[$s, $r] = $c->call('GET', '/exams?type=mock');
check('종류별 목록', count($r['data']) === 1 && $r['data'][0]['id'] === $mockId, $r);
[$s, $r] = $b->call('GET', "/exams/$exId");
check('남의 시험 조회 → 404', $s === 404, $r);
[$s, $r] = $c->call('PUT', "/exams/$exId", ['title' => '2학기 중간고사(수정)', 'memo' => "범위 확인\n교과서 위주"]);
check('시험 수정 (일부 필드만)', $s === 200 && $r['data']['title'] === '2학기 중간고사(수정)' && $r['data']['start_date'] === $d14 && str_contains($r['data']['memo'], "\n"), $r);

echo "[시험 과목 · 범위]\n";
[$s, $r] = $c->call('POST', "/exams/$exId/subjects", ['subject_id' => $sid['공통국어'], 'exam_date' => $d14, 'period' => 1, 'target_score' => 90]);
check('시험 과목 추가', $s === 200 && count($r['data']['subjects']) === 1 && $r['data']['subjects'][0]['target_score'] === 90 && $r['data']['subjects'][0]['d_day'] === 14, $r);
$esKor = $r['data']['subjects'][0]['id'] ?? 0;
[$s, $r] = $c->call('POST', "/exams/$exId/subjects", ['subject_id' => $sid['공통수학'], 'exam_date' => $d15, 'period' => 2, 'target_grade' => 1]);
$esMath = 0;
foreach ($r['data']['subjects'] ?? [] as $x) {
    if ($x['subject_name'] === '공통수학') {
        $esMath = $x['id'];
    }
}
check('두 번째 과목 (시험일순 정렬)', $s === 200 && count($r['data']['subjects']) === 2 && $r['data']['subjects'][1]['target_grade'] === 1, $r);
[$s, $r] = $c->call('POST', "/exams/$exId/subjects", ['subject_id' => $sid['공통수학'], 'exam_date' => $d15]);
check('같은 과목 중복 → 409', $s === 409, $r);
[$s, $r] = $c->call('POST', "/exams/$exId/subjects", ['subject_id' => $sid['공통영어'], 'exam_date' => ReviewControllerDate::plus($today, 30)]);
check('시험 기간 밖의 날짜 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'exam_date', $r);
[$s, $r] = $c->call('POST', "/exams/$exId/subjects", ['subject_id' => $sid['공통영어'], 'exam_date' => $d14, 'target_score' => 101]);
check('목표 점수 범위 오류 → 422', $s === 422, $r);
[$s, $r] = $c->call('POST', "/exams/$exId/subjects", ['subject_id' => $subId, 'exam_date' => $d14]);
check('남의 과목 → 404', $s === 404, $r);

[$s, $r] = $c->call('POST', "/exam-subjects/$esKor/scopes", ['label' => '문학 1~2단원', 'page_start' => 10, 'page_end' => 49]);
check('범위 직접 입력 (40쪽)', $s === 200 && $r['data']['subjects'][0]['scopes'][0]['label'] === '문학 1~2단원', $r);
[$s, $r] = $c->call('POST', "/exam-subjects/$esKor/scopes", ['label' => '쪽수 오류', 'page_start' => 50, 'page_end' => 10]);
check('범위 쪽수 오류 → 422', $s === 422, $r);

[$s, $r] = $c->call('POST', '/textbooks', ['subject_id' => $sid['공통수학'], 'title' => '수학 교과서']);
$mathTb = $r['data']['id'];
[$s, $r] = $c->call('POST', '/units', ['textbook_id' => $mathTb, 'title' => '1. 다항식', 'page_start' => 8, 'page_end' => 47]);
$mu1 = $r['data']['id'];
[$s, $r] = $c->call('POST', '/units', ['textbook_id' => $mathTb, 'title' => '2. 방정식', 'page_start' => 48, 'page_end' => 87]);
$mu2 = $r['data']['id'];
[$s, $r] = $c->call('POST', '/textbooks', ['subject_id' => $sid['공통국어'], 'title' => '국어 교과서']);
[$s, $r] = $c->call('POST', '/units', ['textbook_id' => $r['data']['id'], 'title' => '국어 단원']);
$korUnit = $r['data']['id'];

[$s, $r] = $c->call('POST', "/exam-subjects/$esMath/scopes", ['unit_ids' => [$mu1, $mu2]]);
$mathScopes = $r['data']['subjects'][1]['scopes'] ?? [];
check('교재 단원을 시험 범위로 추가 (제목·쪽수 복사)', $s === 200 && count($mathScopes) === 2 && $mathScopes[0]['label'] === '1. 다항식' && $mathScopes[0]['page_end'] === 47 && $mathScopes[0]['unit_id'] === $mu1, $r);
[$s, $r] = $c->call('POST', "/exam-subjects/$esMath/scopes", ['unit_ids' => [$mu1, $mu2]]);
check('같은 단원을 다시 추가해도 중복되지 않음', $s === 200 && count($r['data']['subjects'][1]['scopes']) === 2, $r);
[$s, $r] = $c->call('POST', "/exam-subjects/$esMath/scopes", ['unit_ids' => [$korUnit]]);
check('다른 과목의 단원 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'unit_ids', $r);
[$s, $r] = $b->call('POST', "/exam-subjects/$esMath/scopes", ['label' => '해킹']);
check('남의 시험 과목에 범위 추가 → 404', $s === 404, $r);

$scopeId = $mathScopes[0]['id'] ?? 0;
[$s, $r] = $c->call('PUT', "/exam-scopes/$scopeId", ['progress' => 60]);
check('진도 갱신', $s === 200 && $r['data']['subjects'][1]['scopes'][0]['progress'] === 60, $r);
check('과목 진도 = 범위 진도의 평균 (60, 0 → 30)', $r['data']['subjects'][1]['progress'] === 30, $r['data']['subjects'][1]['progress'] ?? null);
[$s, $r] = $c->call('PUT', "/exam-scopes/$scopeId", ['progress' => 120]);
check('진도 범위 오류 → 422', $s === 422, $r);

echo "[대비 계획]\n";
[$s, $r] = $c->call('POST', "/exams/$exId/plan/preview");
$plan = $r['data'] ?? [];
$okDates = $s === 200 && count($plan['items']) > 0;
foreach ($plan['items'] ?? [] as $it) {
    $examDate = $it['exam_subject_id'] === $esKor ? $d14 : $d15;
    if ($it['task_date'] < $today || $it['task_date'] >= $examDate) {
        $okDates = false;
    }
}
check('미리보기: 오늘부터 시험 전날까지 날짜로 계획 생성', $okDates, $r);
check('미리보기: 두 과목 모두 포함, 건너뛴 과목 없음', count(array_unique(array_column($plan['items'], 'exam_subject_id'))) === 2 && $plan['skipped'] === [], $plan['skipped'] ?? null);
check('미리보기는 DB를 바꾸지 않음', count($c->call('GET', "/exams/$exId/tasks")[1]['data']) === 0);

$items = array_map(fn($i) => ['exam_subject_id' => $i['exam_subject_id'], 'title' => $i['title'], 'task_date' => $i['task_date'], 'est_minutes' => $i['est_minutes']], $plan['items']);
[$s, $r] = $c->call('POST', "/exams/$exId/plan/apply", ['items' => $items]);
check('계획 확정 → 할 일 생성', $s === 200 && $r['data']['created'] === count($items) && $r['data']['kept_done'] === 0, $r);
[$s, $r] = $c->call('GET', "/exams/$exId/tasks");
check('시험 계획 할 일 목록 (출처 exam)', count($r['data']) === count($items) && $r['data'][0]['source'] === 'exam' && $r['data'][0]['subject_name'] !== null, $r);
$firstTask = $r['data'][0]['id'];
[$s, $r] = $c->call('GET', '/tasks');
$todayExam = array_filter($r['data'], fn($t) => $t['source'] === 'exam');
check('오늘 할 일에도 시험 계획이 보임', count($todayExam) >= 1, $r);
[$s, $r] = $c->call('POST', "/tasks/$firstTask/toggle", ['done' => true]);
[$s, $r] = $c->call('GET', "/exams/$exId");
check('시험 과목별 계획 달성 수', array_sum(array_column($r['data']['subjects'], 'task_done')) === 1 && array_sum(array_column($r['data']['subjects'], 'task_total')) === count($items), $r['data']['subjects'] ?? null);

$fewer = array_slice($items, 0, 3);
[$s, $r] = $c->call('POST', "/exams/$exId/plan/apply", ['items' => $fewer]);
check('다시 확정하면 미완료 계획만 교체, 완료한 할 일은 유지', $s === 200 && $r['data']['created'] === 3 && $r['data']['kept_done'] === 1, $r);
[$s, $r] = $c->call('GET', "/exams/$exId/tasks");
check('교체 후 할 일 = 완료 1 + 새 3', count($r['data']) === 4, count($r['data']));

[$s, $r] = $c->call('POST', "/exams/$exId/plan/apply", ['items' => [['exam_subject_id' => $esKor, 'title' => '지난 날짜', 'task_date' => ReviewControllerDate::plus($today, -1)]]]);
check('과거 날짜 계획 → 422', $s === 422, $r);
[$s, $r] = $c->call('POST', "/exams/$exId/plan/apply", ['items' => [['exam_subject_id' => $esKor, 'title' => '시험 당일', 'task_date' => $d14]]]);
check('시험 당일 계획 → 422', $s === 422, $r);
[$s, $r] = $c->call('POST', "/exams/$exId/plan/apply", ['items' => [['exam_subject_id' => 999999, 'title' => '없는 과목', 'task_date' => $today]]]);
check('이 시험에 없는 과목 → 422', $s === 422, $r);
[$s, $r] = $b->call('POST', "/exams/$exId/plan/apply", ['items' => []]);
check('남의 시험 계획 확정 → 404', $s === 404, $r);
[$s, $r] = $b->call('POST', "/exams/$exId/plan/preview");
check('남의 시험 계획 미리보기 → 404', $s === 404, $r);
[$s, $r] = $c->call('POST', "/exams/$mockId/plan/preview");
check('시험 과목이 없으면 미리보기 → 422', $s === 422, $r);

echo "[결과 입력]\n";
[$s, $r] = $c->call('POST', "/exams/$mockId/subjects", ['subject_id' => $sid['공통수학'], 'exam_date' => ReviewControllerDate::plus($today, -10), 'target_score' => 90]);
$esMock = $r['data']['subjects'][0]['id'] ?? 0;
[$s, $r] = $c->call('POST', "/exams/$mockId/plan/preview");
check('지난 시험 과목은 계획에서 건너뜀 (이유 표시)', $s === 200 && $r['data']['items'] === [] && count($r['data']['skipped']) === 1, $r);
[$s, $r] = $c->call('PUT', "/exam-subjects/$esMock", ['actual_score' => 101]);
check('실제 점수 범위 오류 → 422', $s === 422, $r);
[$s, $r] = $c->call('PUT', "/exam-subjects/$esMock", ['actual_score' => 88, 'actual_grade' => 2]);
check('시험 결과 입력', $s === 200 && $r['data']['subjects'][0]['actual_score'] === 88 && $r['data']['subjects'][0]['target_score'] === 90, $r);
[$s, $r] = $c->call('GET', '/exams/results?type=mock');
check('모의고사 성적 목록', $s === 200 && count($r['data']) === 1 && $r['data'][0]['actual_grade'] === 2 && $r['data'][0]['subject_name'] === '공통수학', $r);
[$s, $r] = $c->call('GET', '/exams/results?type=midterm');
check('결과가 없는 종류는 빈 목록', $r['data'] === [], $r);
[$s, $r] = $c->call('PUT', "/exam-subjects/$esMock", ['actual_score' => null, 'actual_grade' => null]);
check('결과 지우기', $r['data']['subjects'][0]['actual_score'] === null && $r['data']['subjects'][0]['target_score'] === 90, $r);

echo "[시험 삭제]\n";
[$s, $r] = $c->call('DELETE', "/exam-scopes/$scopeId");
check('범위 삭제', $s === 200 && count($r['data']['subjects'][1]['scopes']) === 1, $r);
[$s, $r] = $c->call('DELETE', "/exam-subjects/$esKor");
check('시험 과목 삭제', $s === 200 && count($r['data']['subjects']) === 1, $r);
[$s, $r] = $c->call('GET', "/exams/$exId/tasks");
check('시험 과목을 지우면 그 과목의 계획 할 일도 삭제', count(array_filter($r['data'], fn($t) => $t['subject_name'] === '공통국어')) === 0, $r);
[$s, $r] = $b->call('DELETE', "/exams/$exId");
check('남의 시험 삭제 → 404', $s === 404, $r);
[$s, $r] = $c->call('DELETE', "/exams/$exId");
check('시험 삭제', $s === 200, $r);
[$s, $r] = $c->call('GET', '/tasks?from=' . $today . '&to=' . $d14);
check('시험을 지우면 계획 할 일도 모두 삭제', count(array_filter($r['data'], fn($t) => $t['source'] === 'exam')) === 0, $r);
[$s, $r] = $c->call('GET', "/exams/$exId");
check('삭제된 시험 → 404', $s === 404, $r);

echo "[수행평가]\n";
$d10 = ReviewControllerDate::plus($today, 10);
$assessTasks = static function (Client $cl, string $from, string $to): array {
    [, $r] = $cl->call('GET', "/tasks?from=$from&to=$to");
    return array_values(array_filter($r['data'], fn($t) => $t['source'] === 'assessment'));
};
[$s, $r] = $c->call('POST', '/assessments', ['subject_id' => $sid['통합사회'], 'title' => '모둠 발표', 'kind' => 'presentation', 'max_score' => 20, 'weight_pct' => 20, 'due_date' => $d10, 'rubric' => "발표 내용 10\n태도 10", 'auto_steps' => true]);
check('수행평가 등록 + 기본 단계 자동 생성(발표 5단계)', $s === 200 && count($r['data']['steps']) === 5 && $r['data']['status'] === 'planned' && $r['data']['d_day'] === 10 && $r['data']['kind_label'] === '발표', $r);
$asId = $r['data']['id'] ?? 0;
$steps = $r['data']['steps'] ?? [];
check('단계 목표일: 첫 단계 오늘, 마지막 단계 마감일', ($steps[0]['target_date'] ?? '') === $today && ($steps[4]['target_date'] ?? '') === $d10, array_column($steps, 'target_date'));
check('단계마다 오늘 할 일(출처 assessment)이 생김', count($assessTasks($c, $today, $d10)) === 5);
$firstTask = $assessTasks($c, $today, $today)[0] ?? [];
check('할 일 제목 = 수행평가 · 단계, 과목 연결', ($firstTask['title'] ?? '') === '모둠 발표 · 주제 정하기' && ($firstTask['subject_name'] ?? '') === '통합사회', $firstTask);

[$s, $r] = $c->call('POST', '/assessments', ['subject_id' => $sid['통합사회'], 'title' => '이름만', 'due_date' => 'abc']);
check('마감일 형식 오류 → 422', $s === 422, $r);
[$s, $r] = $c->call('POST', '/assessments', ['subject_id' => $sid['통합사회'], 'title' => '종류 오류', 'kind' => 'quiz', 'due_date' => $d10]);
check('종류 오류 → 422', $s === 422, $r);
[$s, $r] = $c->call('POST', '/assessments', ['subject_id' => $subId, 'title' => '남의 과목', 'due_date' => $d10]);
check('남의 과목 → 404', $s === 404, $r);
[$s, $r] = $c->call('POST', '/assessments', ['subject_id' => $sid['공통영어'], 'title' => '단계 없이', 'kind' => 'written', 'due_date' => ReviewControllerDate::plus($today, 20)]);
check('자동 단계를 끄면 단계 없이 등록', $s === 200 && $r['data']['steps'] === [], $r);
$asPlain = $r['data']['id'] ?? 0;

[$s, $r] = $c->call('GET', '/assessments');
$mine = array_values(array_filter($r['data'], fn($x) => $x['id'] === $asId))[0] ?? [];
check('목록: 단계 진행·D-day', ($mine['step_total'] ?? 0) === 5 && ($mine['step_done'] ?? -1) === 0 && ($mine['d_day'] ?? 0) === 10 && array_key_exists('exam_warning', $mine) && $mine['exam_warning'] === null, $mine);
check('목록은 마감 가까운 순', $r['data'][0]['id'] === $asId && $r['data'][1]['id'] === $asPlain, array_column($r['data'], 'id'));

[$s, $r] = $c->call('PUT', "/assessments/$asId", ['max_score' => 10, 'score' => 15]);
check('점수가 배점보다 크면 → 422', $s === 422 && ($r['error']['field'] ?? '') === 'score', $r);

echo "[수행평가 단계 ↔ 할 일]\n";
$s1 = $steps[0]['id'];
$s2 = $steps[1]['id'];
[$s, $r] = $c->call('POST', "/assessment-steps/$s1/toggle");
check('단계 완료 → 상태 예정이 진행중으로', $s === 200 && $r['data']['status'] === 'in_progress' && $r['data']['steps'][0]['done'] === true, $r);
check('연결된 할 일도 완료로', ($assessTasks($c, $today, $today)[0]['done'] ?? false) === true);
$taskS2 = $assessTasks($c, ReviewControllerDate::plus($today, 1), $d10)[0];
[$s, $r] = $c->call('POST', "/tasks/{$taskS2['id']}/toggle", ['done' => true]);
[$s, $r] = $c->call('GET', "/assessments/$asId");
$doneSteps = array_column(array_filter($r['data']['steps'], fn($x) => $x['done']), 'id');
check('할 일에서 체크하면 단계도 완료', in_array($s2, $doneSteps, true) || count($doneSteps) === 2, $doneSteps);
[$s, $r] = $c->call('POST', "/assessment-steps/$s1/toggle", ['done' => false]);
check('단계 완료 취소 → 할 일도 미완료', $r['data']['steps'][0]['done'] === false && ($assessTasks($c, $today, $today)[0]['done'] ?? true) === false);

$newDate = ReviewControllerDate::plus($today, 2);
[$s, $r] = $c->call('PUT', "/assessment-steps/$s1", ['target_date' => $newDate, 'title' => '주제 확정']);
$moved = array_values(array_filter($assessTasks($c, $newDate, $newDate), fn($t) => $t['title'] === '모둠 발표 · 주제 확정'));
check('단계 목표일·이름을 바꾸면 할 일도 따라감', $s === 200 && count($moved) === 1, $r);
[$s, $r] = $c->call('PUT', "/tasks/{$moved[0]['id']}", ['task_date' => ReviewControllerDate::plus($today, 3)]);
[$s, $r] = $c->call('GET', "/assessments/$asId");
check('할 일 날짜를 옮기면 단계 목표일도 따라감', $r['data']['steps'][0]['target_date'] === ReviewControllerDate::plus($today, 3), $r['data']['steps'][0]);
[$s, $r] = $c->call('PUT', "/assessment-steps/$s1", ['target_date' => null]);
check('목표일을 비우면 할 일이 사라짐 (단계는 유지)', $s === 200 && $r['data']['steps'][0]['target_date'] === null && count($assessTasks($c, $today, $d10)) === 4, count($assessTasks($c, $today, $d10)));
[$s, $r] = $c->call('POST', "/assessments/$asId/steps", ['title' => '리허설', 'target_date' => ReviewControllerDate::plus($today, 8)]);
check('단계 추가(목표일 있음) → 할 일 생성', $s === 200 && count($r['data']['steps']) === 6 && count($assessTasks($c, $today, $d10)) === 5, $r);
$stepNew = end($r['data']['steps'])['id'];
[$s, $r] = $c->call('DELETE', "/assessment-steps/$stepNew");
check('단계 삭제 → 할 일도 삭제', $s === 200 && count($r['data']['steps']) === 5 && count($assessTasks($c, $today, $d10)) === 4, $r);
$taskToDelete = $assessTasks($c, $today, $d10)[0];
[$s, $r] = $c->call('DELETE', "/tasks/{$taskToDelete['id']}");
[$s, $r] = $c->call('GET', "/assessments/$asId");
check('할 일을 지우면 그 단계의 목표일도 비움', count(array_filter($r['data']['steps'], fn($x) => $x['target_date'] === null)) === 2, array_column($r['data']['steps'], 'target_date'));

[$s, $r] = $c->call('PUT', "/assessments/$asId", ['title' => '모둠 발표(수정)']);
check('수행평가 이름을 바꾸면 단계 할 일 제목도 바뀜', count(array_filter($assessTasks($c, $today, $d10), fn($t) => str_starts_with($t['title'], '모둠 발표(수정) · '))) === 3, array_column($assessTasks($c, $today, $d10), 'title'));

[$s, $r] = $b->call('GET', "/assessments/$asId");
check('남의 수행평가 조회 → 404', $s === 404, $r);
[$s, $r] = $b->call('POST', "/assessment-steps/$s2/toggle");
check('남의 단계 체크 → 404', $s === 404, $r);
[$s, $r] = $b->call('PUT', "/assessments/$asId", ['title' => '해킹']);
check('남의 수행평가 수정 → 404', $s === 404, $r);
[$s, $r] = $b->call('DELETE', "/assessments/$asId");
check('남의 수행평가 삭제 → 404', $s === 404, $r);

echo "[수행평가 경고 · 점수 · 삭제]\n";
[$s, $r] = $c->call('POST', '/exams', ['type' => 'midterm', 'title' => '곧 있을 중간고사', 'start_date' => ReviewControllerDate::plus($today, 12), 'end_date' => ReviewControllerDate::plus($today, 15)]);
$exWarn = $r['data']['id'];
[$s, $r] = $c->call('GET', "/assessments/$asId");
check('마감이 시험 시작 7일 전 이내면 경고', is_string($r['data']['exam_warning']) && str_contains($r['data']['exam_warning'], '곧 있을 중간고사'), $r['data']['exam_warning'] ?? null);
[$s, $r] = $c->call('GET', '/assessments');
$plain = array_values(array_filter($r['data'], fn($x) => $x['id'] === $asPlain))[0];
check('시험과 멀리 떨어진 마감은 경고 없음', $plain['exam_warning'] === null, $plain);
[$s, $r] = $c->call('PUT', "/assessments/$asId", ['status' => 'submitted']);
check('제출하면 경고 사라짐', $s === 200 && $r['data']['status'] === 'submitted' && $r['data']['exam_warning'] === null, $r);
[$s, $r] = $c->call('PUT', "/assessments/$asId", ['status' => 'graded', 'max_score' => 20, 'score' => 17.5]);
check('채점 완료 + 점수(소수 1자리)', $s === 200 && $r['data']['status'] === 'graded' && (float) $r['data']['score'] === 17.5, $r);
[$s, $r] = $c->call('GET', '/assessments');
check('완료된 수행평가는 목록 뒤쪽', end($r['data'])['id'] === $asId, array_column($r['data'], 'id'));
[$s, $r] = $c->call('PUT', "/assessments/$asId", ['status' => 'done']);
check('상태 값 오류 → 422', $s === 422, $r);
$c->call('DELETE', "/exams/$exWarn");

[$s, $r] = $c->call('DELETE', "/assessments/$asId");
check('수행평가 삭제', $s === 200, $r);
check('삭제하면 단계 할 일도 모두 사라짐', count($assessTasks($c, $today, $d10)) === 0, count($assessTasks($c, $today, $d10)));
[$s, $r] = $c->call('GET', "/assessments/$asId");
check('삭제된 수행평가 → 404', $s === 404, $r);

echo "[관리자 · 가입 방식 · 회원 탈퇴]\n";
$adm = new Client($base);
$adm->boot();
[$s, $r] = $adm->call('POST', '/auth/login', ['login_id' => 'testadmin', 'password' => 'adminpass1']);
if ($s === 200) {
    $adm->csrf = $r['data']['csrf'];
} else {
    $adm->signup('testadmin', 'adminpass1');
}
[$s, $r] = $adm->call('GET', '/auth/me');
check('관리자 me → is_admin', $r['data']['user']['is_admin'] === true, $r);
[$s, $r] = $a->call('GET', '/auth/me');
check('일반 회원은 is_admin false', $r['data']['user']['is_admin'] === false, $r);
[$s, $r] = $a->call('GET', '/admin/users');
check('일반 회원이 관리자 API → 403', $s === 403, $r);
[$s, $r] = (new Client($base))->call('GET', '/admin/users');
check('비로그인 관리자 API → 401', $s === 401, $r);
$adm->call('PUT', '/admin/settings', ['signup_mode' => 'open']);

$adm->call('PUT', '/admin/settings', ['signup_mode' => 'approval']);
$pend = new Client($base);
$pend->boot();
[$s, $r] = $pend->call('POST', '/auth/signup', ['login_id' => "pd$run", 'email' => "pd$run@example.com", 'password' => 'passw0rd123', 'name' => '대기']);
check('승인제: 가입하면 대기 상태, 로그인 안 됨', $s === 200 && ($r['data']['pending'] ?? false) === true && !isset($r['data']['user']), $r);
[$s, $r] = $pend->call('GET', '/subjects');
check('대기 회원은 API 사용 불가', $s === 401, $r);
[$s, $r] = $pend->call('POST', '/auth/login', ['login_id' => "pd$run", 'password' => 'wrongpass1']);
check('대기 회원이 틀린 비밀번호 → 일반 오류(상태 노출 안 함)', $s === 401 && $r['error']['code'] === 'bad_credentials', $r);
[$s, $r] = $pend->call('POST', '/auth/login', ['login_id' => "pd$run", 'password' => 'passw0rd123']);
check('대기 회원 로그인 → 403 pending', $s === 403 && $r['error']['code'] === 'pending', $r);
[$s, $r] = $adm->call('GET', '/admin/users?status=pending');
$pid = 0;
foreach ($r['data']['users'] as $u) {
    if ($u['login_id'] === "pd$run") {
        $pid = $u['id'];
    }
}
check('관리자 목록에 대기 회원', $pid > 0 && count($r['data']['users']) >= 1, $r);
[$s, $r] = $adm->call('POST', "/admin/users/$pid/status", ['status' => 'active']);
check('승인', $s === 200, $r);
$pend->boot();
[$s, $r] = $pend->call('POST', '/auth/login', ['login_id' => "pd$run", 'password' => 'passw0rd123']);
check('승인 후 로그인', $s === 200, $r);
$pend->csrf = $r['data']['csrf'];

$adm->call('PUT', '/admin/settings', ['signup_mode' => 'closed']);
$cl = new Client($base);
$cl->boot();
[$s, $r] = $cl->call('POST', '/auth/signup', ['login_id' => "cl$run", 'email' => "cl$run@example.com", 'password' => 'passw0rd123', 'name' => '닫힘']);
check('가입 중단: 새 가입 403', $s === 403 && $r['error']['code'] === 'signup_closed', $r);
[$s, $r] = $cl->call('GET', '/auth/me');
check('me 에 가입 방식 포함', $r['data']['signup_mode'] === 'closed', $r);
[$s, $r] = $adm->call('PUT', '/admin/settings', ['signup_mode' => 'weird']);
check('가입 방식 값 오류 → 422', $s === 422, $r);
$adm->call('PUT', '/admin/settings', ['signup_mode' => 'open']);

[$s, $r] = $adm->call('POST', "/admin/users/$pid/status", ['status' => 'blocked']);
check('차단', $s === 200, $r);
[$s, $r] = $pend->call('GET', '/subjects');
check('차단하면 쓰던 세션도 즉시 무효', $s === 401, $r);
$pend->boot();
[$s, $r] = $pend->call('POST', '/auth/login', ['login_id' => "pd$run", 'password' => 'passw0rd123']);
check('차단 회원 로그인 → 403 blocked', $s === 403 && $r['error']['code'] === 'blocked', $r);
$adm->call('POST', "/admin/users/$pid/status", ['status' => 'active']);

[$s, $r] = $adm->call('POST', "/admin/users/$pid/reset-password");
$temp = $r['data']['temporary_password'] ?? '';
check('비밀번호 초기화 → 임시 비밀번호 발급', $s === 200 && strlen($temp) === 10, $r);
$pend->boot();
[$s, $r] = $pend->call('POST', '/auth/login', ['login_id' => "pd$run", 'password' => 'passw0rd123']);
check('이전 비밀번호는 더 이상 안 됨', $s === 401, $r);
[$s, $r] = $pend->call('POST', '/auth/login', ['login_id' => "pd$run", 'password' => $temp]);
check('임시 비밀번호로 로그인 + 변경 필요 표시', $s === 200 && $r['data']['user']['must_change_password'] === true, $r);
$pend->csrf = $r['data']['csrf'];
[$s, $r] = $pend->call('PUT', '/auth/password', ['current_password' => $temp, 'new_password' => 'brandnew77']);
check('비밀번호 변경', $s === 200, $r);
[$s, $r] = $pend->call('GET', '/auth/me');
check('변경 후 must_change_password 해제 + 로그인 유지', $r['data']['user']['must_change_password'] === false, $r);

[$s, $r] = $adm->call('POST', '/admin/users/' . $u1['id'] . '/reset-password');
$other = new Client($base);
$other->boot();
[$s, $r] = $other->call('POST', '/auth/login', ['login_id' => "u1$run", 'password' => 'newpass456']);
check('다른 기기 세션: 초기화 전 비밀번호는 거부', $s === 401, $r);

[$s, $r] = $adm->call('POST', '/admin/users/' . $adm->call('GET', '/auth/me')[1]['data']['user']['id'] . '/status', ['status' => 'blocked']);
check('관리자 본인은 차단 불가', $s === 422, $r);
[$s, $r] = $adm->call('GET', '/admin/stats');
check('통계', $s === 200 && $r['data']['counts']['active'] >= 2, $r);
[$s, $r] = $adm->call('GET', '/admin/log');
$actions = array_column($r['data']['log'], 'action');
check('관리자 기록에 승인·차단·초기화가 남음', in_array('approve', $actions) && in_array('block', $actions) && in_array('reset_password', $actions), $actions);
[$s, $r] = $adm->call('GET', '/admin/users?q=pd' . $run);
check('회원 검색', count($r['data']['users']) === 1 && $r['data']['users'][0]['login_id'] === "pd$run", $r);

// 회원 탈퇴 · 관리자의 회원 삭제
$bye = new Client($base);
$byeUser = $bye->signup("bye$run");
$bye->call('POST', '/subjects', ['name' => '탈퇴과목']);
[$s, $r] = $bye->call('DELETE', '/auth/account', ['password' => 'wrongpass1']);
check('탈퇴: 비밀번호가 틀리면 422', $s === 422, $r);
[$s, $r] = $bye->call('DELETE', '/auth/account', ['password' => 'passw0rd123']);
check('탈퇴', $s === 200 && $r['data']['deleted'] === true, $r);
$bye->csrf = $r['data']['csrf'];
[$s, $r] = $bye->call('POST', '/auth/login', ['login_id' => "bye$run", 'password' => 'passw0rd123']);
check('탈퇴한 아이디로 로그인 불가', $s === 401, $r);
[$s, $r] = $adm->call('DELETE', '/auth/account', ['password' => 'adminpass1']);
check('관리자 계정은 탈퇴 불가', $s === 422, $r);

[$s, $r] = $adm->call('DELETE', "/admin/users/$pid", ['confirm_login_id' => 'nope']);
check('관리자 삭제: 확인 아이디가 다르면 422', $s === 422, $r);
[$s, $r] = $adm->call('DELETE', "/admin/users/$pid", ['confirm_login_id' => "pd$run"]);
check('관리자가 회원 삭제', $s === 200, $r);
[$s, $r] = $adm->call('GET', '/admin/users?q=pd' . $run);
check('삭제된 회원은 목록에서 사라짐', count($r['data']['users']) === 0, $r);

echo "\n" . ($failures === 0 ? "전체 통과 ($checks)" : "실패 $failures / $checks") . "\n";
exit($failures === 0 ? 0 : 1);
