<?php
declare(strict_types=1);

// 사용: 테스트 서버를 먼저 띄운다.
//   SP_CONFIG=backend/config.test.php php scripts/migrate.php
//   SP_CONFIG=backend/config.test.php php -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8081 scripts/dev-router.php
// 그다음: php backend/tests/api_test.php [http://127.0.0.1:8081]

// 서버가 한국 시간(Asia/Seoul) 기준으로 날짜를 계산하므로 테스트도 같은 시간대를 쓴다.
date_default_timezone_set('Asia/Seoul');
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

echo "\n" . ($failures === 0 ? "전체 통과 ($checks)" : "실패 $failures / $checks") . "\n";
exit($failures === 0 ? 0 : 1);
