<?php
declare(strict_types=1);

// 사용: php backend/tests/plan_test.php   (DB·서버 없이 실행)
require __DIR__ . '/../src/bootstrap.php';

use App\Services\PlanService;
use App\Services\QuizGrader;
use App\Services\AiQuestions;
use App\Services\Neis;
use App\Services\TocParser;

$fail = 0;
$n = 0;
function check(string $label, bool $cond, mixed $detail = null): void
{
    global $fail, $n;
    $n++;
    if ($cond) {
        echo "  ok   $label\n";
        return;
    }
    $fail++;
    echo "  FAIL $label" . ($detail !== null ? ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

function subject(int $id, string $examDate, array $scopes = [], string $name = '수학'): array
{
    return ['id' => $id, 'subject_id' => $id, 'subject_name' => $name, 'exam_date' => $examDate, 'scopes' => $scopes];
}

echo "[구간 나누기]\n";
$expected = [0 => [0, 0, 0], 1 => [0, 0, 1], 2 => [0, 1, 1], 3 => [1, 1, 1], 4 => [2, 1, 1], 5 => [3, 1, 1], 10 => [6, 2, 2], 14 => [8, 4, 2], 20 => [12, 5, 3]];
foreach ($expected as $days => $want) {
    $got = PlanService::phaseCounts($days);
    check("{$days}일 → 1회독 {$want[0]} · 문제풀이 {$want[1]} · 총정리 {$want[2]}", $got === $want && array_sum($got) === $days, $got);
}
$allOk = true;
for ($d = 1; $d <= 120; $d++) {
    [$a, $b, $c] = PlanService::phaseCounts($d);
    if ($a + $b + $c !== $d || $c < 1 || ($d >= 3 && ($a < 1 || $b < 1))) {
        $allOk = false;
    }
}
check('1~120일 모두: 합계 일치, 총정리 ≥1, 3일 이상이면 모든 단계 ≥1', $allOk);

echo "[날짜 범위]\n";
check('시험 전날까지 포함 (10/27 → 11/9: 13일)', count(PlanService::daysBetween('2026-10-27', '2026-11-09')) === 13);
check('첫 날은 오늘, 마지막 날은 시험 전날', PlanService::daysBetween('2026-10-27', '2026-11-09')[0] === '2026-10-27' && PlanService::daysBetween('2026-10-27', '2026-11-09')[12] === '2026-11-08');
check('시험이 내일이면 오늘 하루', PlanService::daysBetween('2026-10-27', '2026-10-28') === ['2026-10-27']);
check('시험이 오늘이면 없음', PlanService::daysBetween('2026-10-27', '2026-10-27') === []);
check('이미 지난 시험은 없음', PlanService::daysBetween('2026-10-27', '2026-10-01') === []);
check('월말·연말 경계', PlanService::daysBetween('2026-12-30', '2027-01-02') === ['2026-12-30', '2026-12-31', '2027-01-01']);

echo "[계획 생성: 범위 없음]\n";
$r = PlanService::generate([subject(1, '2026-11-09')], '2026-10-27');
$phases = array_count_values(array_column($r['items'], 'phase'));
check('13일: 1회독 8 · 문제풀이 3 · 총정리 2', ($phases['read'] ?? 0) === 8 && ($phases['practice'] ?? 0) === 3 && ($phases['review'] ?? 0) === 2, $phases);
check('하루에 하나씩, 날짜가 겹치지 않음', count(array_unique(array_column($r['items'], 'task_date'))) === 13);
check('마지막 날은 총정리', end($r['items'])['phase'] === 'review' && end($r['items'])['task_date'] === '2026-11-08');
check('첫 날은 1회독', $r['items'][0]['phase'] === 'read' && $r['items'][0]['task_date'] === '2026-10-27');
check('제목에 과목·단계·순번', $r['items'][0]['title'] === '수학 범위 1회독 (1/8)', $r['items'][0]['title']);

echo "[계획 생성: 범위 있음]\n";
$scopes = [
    ['label' => '1. 다항식', 'pages' => 40],
    ['label' => '2. 방정식', 'pages' => 40],
    ['label' => '3. 경우의 수', 'pages' => 20],
];
$r = PlanService::generate([subject(1, '2026-11-09', $scopes)], '2026-10-27');
$read = array_values(array_filter($r['items'], fn($i) => $i['phase'] === 'read'));
$covered = [];
foreach ($read as $i) {
    foreach ($scopes as $sc) {
        if (str_contains($i['title'], $sc['label'])) {
            $covered[$sc['label']] = ($covered[$sc['label']] ?? 0) + 1;
        }
    }
}
check('1회독에 모든 범위가 들어감', count($covered) === 3, $covered);
check('1회독 8일 모두 할 일이 있음 (빈 날 없음)', count($read) === 8, array_column($read, 'task_date'));
check('큰 범위는 여러 날로 쪼개짐 ((1/3) 표기)', str_contains($read[0]['title'], '1. 다항식 (1/3)') && str_contains($read[2]['title'], '(3/3)'), array_column($read, 'title'));
check('1회독은 범위 순서대로 진행', strpos($read[0]['title'], '다항식') !== false && strpos(end($read)['title'], '경우의 수') !== false, array_column($read, 'title'));
check('쪽수가 많은 범위가 먼저(앞쪽 날짜)이고 날짜가 오름차순', array_column($read, 'task_date') === array_values(array_unique(array_column($read, 'task_date'))) && $read[0]['task_date'] < end($read)['task_date']);
$review = array_values(array_filter($r['items'], fn($i) => $i['phase'] === 'review'));
check('총정리에도 모든 범위가 들어감', count(array_filter($scopes, function ($sc) use ($review) { foreach ($review as $i) { if (str_contains($i['title'], $sc['label'])) return true; } return false; })) === 3, array_column($review, 'title'));
check('예상 시간은 1~180분', min(array_column($r['items'], 'est_minutes')) >= 1 && max(array_column($r['items'], 'est_minutes')) <= 180);

echo "[계획 생성: 범위가 1개뿐일 때]\n";
$r = PlanService::generate([subject(1, '2026-11-09', [['label' => '전체 범위', 'pages' => 120]])], '2026-10-27');
check('13일 모두 하루도 빠짐없이 할 일이 있음', count($r['items']) === 13 && count(array_unique(array_column($r['items'], 'task_date'))) === 13, count($r['items']));
check('1회독 첫날 제목 (1/8)', $r['items'][0]['title'] === '수학 범위 1회독 · 전체 범위 (1/8)', $r['items'][0]['title']);
check('1회독 하루 분량 = 120쪽÷8 → 30분 이상', $r['items'][0]['est_minutes'] >= 20 && $r['items'][0]['est_minutes'] <= 90, $r['items'][0]['est_minutes']);

echo "[계획 생성: 범위가 날짜보다 많을 때]\n";
$many = [];
for ($i = 1; $i <= 12; $i++) {
    $many[] = ['label' => "단원$i", 'pages' => null];
}
$r = PlanService::generate([subject(1, '2026-10-30', $many)], '2026-10-27'); // 3일: 1회독 1일 · 문제풀이 1일 · 총정리 1일
$byPhase = [];
foreach ($r['items'] as $i) {
    $byPhase[$i['phase']][] = $i;
}
check('3일이면 단계마다 하루 한 개로 합쳐짐', count($r['items']) === 3 && count($byPhase['read']) === 1, count($r['items']));
check('합쳐진 할 일에 12개 단원이 모두 들어감', substr_count($byPhase['read'][0]['title'], '단원') === 12, $byPhase['read'][0]['title']);
check('제목은 120자를 넘지 않음', max(array_map(fn($i) => mb_strlen($i['title']), $r['items'])) <= 120);
$long = [];
for ($i = 1; $i <= 12; $i++) {
    $long[] = ['label' => "매우 긴 단원 이름 {$i} 번 · 이차함수와 이차방정식의 관계", 'pages' => null];
}
$r2 = PlanService::generate([subject(1, '2026-10-30', $long)], '2026-10-27');
check('120자를 넘으면 …로 줄임', mb_strlen($r2['items'][0]['title']) === 120 && str_ends_with($r2['items'][0]['title'], '…'), $r2['items'][0]['title']);

echo "[계획 생성: 여러 과목·예외]\n";
$r = PlanService::generate([
    subject(1, '2026-11-09', [], '국어'),
    subject(2, '2026-11-11', [], '수학'),
    subject(3, '2026-10-27', [], '영어'),
    subject(4, '2026-10-20', [], '과학'),
], '2026-10-27');
$bySub = array_count_values(array_column($r['items'], 'exam_subject_id'));
check('과목마다 시험 전날까지의 일수만큼 생성 (국어 13, 수학 15)', ($bySub[1] ?? 0) === 13 && ($bySub[2] ?? 0) === 15, $bySub);
check('시험일이 오늘/과거인 과목은 건너뛰고 이유를 알려 줌', count($r['skipped']) === 2 && $r['skipped'][0]['subject_name'] === '영어', $r['skipped']);
$sorted = $r['items'];
usort($sorted, fn($a, $b) => [$a['task_date'], $a['exam_subject_id']] <=> [$b['task_date'], $b['exam_subject_id']]);
check('결과는 날짜순, 같은 날은 과목순', $sorted === $r['items']);
check('빈 입력', PlanService::generate([], '2026-10-27') === ['items' => [], 'skipped' => []]);

echo "[수행평가 기본 단계]\n";
use App\Services\AssessmentPlan;

$steps = AssessmentPlan::steps('report', '2026-10-27', '2026-11-06');
check('보고서: 5단계, 첫 단계는 오늘 / 마지막 단계는 마감일', count($steps) === 5 && $steps[0]['target_date'] === '2026-10-27' && $steps[4]['target_date'] === '2026-11-06', $steps);
check('날짜가 고르게 늘어남 (0·3·5·8·10일)', array_column($steps, 'target_date') === ['2026-10-27', '2026-10-30', '2026-11-01', '2026-11-04', '2026-11-06'], array_column($steps, 'target_date'));
$steps = AssessmentPlan::steps('presentation', '2026-10-27', '2026-10-27');
check('마감이 오늘이면 모든 단계가 오늘', array_unique(array_column($steps, 'target_date')) === ['2026-10-27'], $steps);
$steps = AssessmentPlan::steps('written', '2026-10-27', '2026-10-01');
check('마감이 지났으면 모든 단계가 마감일', array_unique(array_column($steps, 'target_date')) === ['2026-10-01'] && count($steps) === 4, $steps);
$steps = AssessmentPlan::steps('experiment', '2026-10-27', '2026-10-28');
check('마감이 내일: 날짜는 오늘~내일, 마지막은 마감일', $steps[0]['target_date'] === '2026-10-27' && $steps[4]['target_date'] === '2026-10-28', array_column($steps, 'target_date'));
check('알 수 없는 종류는 기타(3단계)', count(AssessmentPlan::steps('zzz', '2026-10-27', '2026-11-06')) === 3);
$allKinds = true;
foreach (array_keys(AssessmentPlan::KINDS) as $k) {
    $st = AssessmentPlan::steps($k, '2026-10-27', '2026-12-01');
    $dates = array_column($st, 'target_date');
    $sorted = $dates;
    sort($sorted);
    if (count($st) < 3 || $dates !== $sorted || end($dates) !== '2026-12-01' || $dates[0] !== '2026-10-27') {
        $allKinds = false;
    }
}
check('모든 종류: 3단계 이상, 날짜 오름차순, 오늘~마감일', $allKinds);

echo "[알림 계획]\n";
$base = ['today' => '2026-10-27', 'time' => '08:00', 'settings' => [], 'tasks' => [], 'reviews' => [], 'exams' => [], 'assessments' => []];
$keys = static fn(array $in): array => array_column(App\Services\NotificationPlanner::plan($in), 'dedupe_key');
check('데이터가 없으면 알림 없음', $keys($base) === []);

$withTasks = $base + [];
$withTasks['tasks'] = [['title' => '수학 문제집', 'done' => false, 'est_minutes' => 60], ['title' => '영어 단어', 'done' => true, 'est_minutes' => 30]];
$p = App\Services\NotificationPlanner::plan($withTasks);
check('아침 시각 이후 오늘 할 일 요약', count($p) === 1 && $p[0]['dedupe_key'] === 'morning:2026-10-27' && str_contains($p[0]['title'], '2개') && str_contains((string) $p[0]['body'], '예상 1시간'), $p);
check('아침 시각 전에는 아직 안 만듦', $keys(['time' => '07:00'] + $withTasks) === []);
check('저녁 시각 전에는 남은 할 일 알림 없음', !in_array('evening:2026-10-27', $keys($withTasks), true));
check('저녁에 남은 할 일이 있으면 알림', in_array('evening:2026-10-27', $keys(['time' => '21:30'] + $withTasks), true));
$allDone = $withTasks;
$allDone['tasks'][0]['done'] = true;
check('다 끝냈으면 저녁 알림 없음', !in_array('evening:2026-10-27', $keys(['time' => '21:30'] + $allDone), true));
check('저녁 알림을 끌 수 있음(null)', !in_array('evening:2026-10-27', $keys(['time' => '22:00', 'settings' => ['evening_time' => null]] + $withTasks), true));
check('할 일 알림을 끌 수 있음', $keys(['settings' => ['tasks_on' => false]] + $withTasks) === []);
check('내가 정한 아침 시각을 따름', $keys(['time' => '08:00', 'settings' => ['morning_time' => '09:00']] + $withTasks) === []);

$rv = ['reviews' => ['수학 1단원', '영어 2과', '과학', '국어']] + $base;
$p = App\Services\NotificationPlanner::plan($rv);
check('복습 알림: 개수와 앞 3개 + 외', $p[0]['dedupe_key'] === 'review:2026-10-27' && str_contains($p[0]['title'], '4개') && str_contains((string) $p[0]['body'], '외') && !str_contains((string) $p[0]['body'], '국어'), $p);

$ex = static fn(string $start): array => ['exams' => [['id' => 5, 'title' => '중간고사', 'start_date' => $start]]] + $base;
check('시험 D-7/3/1/0 에 알림', $keys($ex('2026-11-03')) === ['exam:5:7'] && $keys($ex('2026-10-30')) === ['exam:5:3'] && $keys($ex('2026-10-28')) === ['exam:5:1'] && $keys($ex('2026-10-27')) === ['exam:5:0']);
check('시험 D-5·D-2 에는 알림 없음', $keys($ex('2026-11-01')) === [] && $keys($ex('2026-10-29')) === []);
check('지난 시험은 알림 없음', $keys($ex('2026-10-20')) === []);
check('시험 D-day 문구', App\Services\NotificationPlanner::plan($ex('2026-10-30'))[0]['title'] === '중간고사 D-3');

$as = static fn(string $due, string $status = 'planned'): array => ['assessments' => [['id' => 9, 'title' => '과학 보고서', 'due_date' => $due, 'status' => $status]]] + $base;
check('수행평가 마감 D-3/1/0 알림', $keys($as('2026-10-30')) === ['assess:9:3'] && $keys($as('2026-10-28')) === ['assess:9:1'] && $keys($as('2026-10-27')) === ['assess:9:0']);
check('수행평가 마감 D-2 는 알림 없음', $keys($as('2026-10-29')) === []);
check('제출·채점 끝난 수행평가는 알림 없음', $keys($as('2026-10-28', 'submitted')) === [] && $keys($as('2026-10-28', 'graded')) === []);
check('마감 지난 미제출은 하루 한 번(3일까지)', $keys($as('2026-10-25')) === ['assess:9:late:2026-10-27'] && $keys($as('2026-10-20')) === []);
check('같은 날 다시 계산해도 같은 key (중복 방지)', $keys($as('2026-10-28')) === $keys($as('2026-10-28')));
check('날짜가 바뀌면 key 도 바뀜', $keys(['today' => '2026-10-28'] + $withTasks) === ['morning:2026-10-28']);

echo "[웹 푸시 암호화]\n";
$ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$auth = random_bytes(16);
$msg = json_encode(['title' => '알림', 'body' => '내용'], JSON_UNESCAPED_UNICODE);
$bodyBytes = App\Services\WebPush::encrypt($msg, App\Services\WebPush::publicPoint($ua), $auth);
// 받는 쪽 입장에서 RFC 8291 대로 풀어 본다.
$salt = substr($bodyBytes, 0, 16);
$idlen = ord($bodyBytes[20]);
$asPub = substr($bodyBytes, 21, $idlen);
$cipher = substr($bodyBytes, 21 + $idlen);
$secret = openssl_pkey_derive(App\Services\WebPush::publicPem($asPub), $ua, 32);
$ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . App\Services\WebPush::publicPoint($ua) . $asPub, $auth);
$cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
$nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
$plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
check('암호화한 본문을 받는 쪽에서 복호화 (끝 구분자 0x02)', $plain === $msg . "\x02", $plain);
check('헤더: record size 4096, 키 길이 65', unpack('N', substr($bodyBytes, 16, 4))[1] === 4096 && $idlen === 65);
check('엉뚱한 auth 비밀로는 복호화 실패', openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', hash_hkdf('sha256', hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . App\Services\WebPush::publicPoint($ua) . $asPub, random_bytes(16)), 16, "Content-Encoding: aes128gcm\0", $salt), OPENSSL_RAW_DATA, $nonce, substr($cipher, -16)) === false);

$v = App\Services\WebPush::generateVapidKeys();
$hdr = App\Services\WebPush::vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc', $v, 1900000000);
preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $hdr, $m);
$claims = json_decode(App\Services\WebPush::unb64u($m[2]), true);
check('VAPID: aud 는 푸시 서비스 origin, exp 는 12시간 뒤', $claims['aud'] === 'https://fcm.googleapis.com' && $claims['exp'] === 1900043200, $claims);
$raw = App\Services\WebPush::unb64u($m[3]);
$toDer = static function (string $rs): string {
    $enc = static function (string $x): string {
        $t = ltrim($x, "\0");
        return $t === '' ? "\0" : ((ord($t[0]) & 0x80 ? "\0" : '') . $t);
    };
    $r = $enc(substr($rs, 0, 32));
    $s = $enc(substr($rs, 32));
    $body = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
    return "\x30" . chr(strlen($body)) . $body;
};
check('VAPID 서명이 공개키로 검증됨', strlen($raw) === 64 && openssl_verify($m[1] . '.' . $m[2], $toDer($raw), App\Services\WebPush::publicPem(App\Services\WebPush::unb64u($m[4])), OPENSSL_ALGO_SHA256) === 1);
check('push 주소 검증: https 외부 도메인만', App\Services\WebPush::endpointAllowed('https://fcm.googleapis.com/fcm/send/x') && !App\Services\WebPush::endpointAllowed('http://fcm.googleapis.com/x') && !App\Services\WebPush::endpointAllowed('https://127.0.0.1/x') && !App\Services\WebPush::endpointAllowed('https://localhost/x') && !App\Services\WebPush::endpointAllowed('https://[::1]/x') && !App\Services\WebPush::endpointAllowed('https://intranet/x'));

echo "[문제 형식·채점]\n";
$thrown = static function (callable $f): ?string {
    try {
        $f();
        return null;
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }
};
$mcq = QuizGrader::normalize(['type' => 'mcq', 'prompt' => ' 1+1은? ', 'choices' => ['1', '2', '3'], 'answer' => '1']);
check('객관식 정규화 (앞뒤 공백 제거, 정답 번호 문자열)', $mcq['prompt'] === '1+1은?' && $mcq['answer'] === '1' && $mcq['choices'] === ['1', '2', '3'], $mcq);
check('객관식: 보기 1개 · 빈 보기 · 범위 밖 정답은 거부', $thrown(fn() => QuizGrader::normalize(['type' => 'mcq', 'prompt' => 'x', 'choices' => ['a'], 'answer' => '0'])) !== null
    && $thrown(fn() => QuizGrader::normalize(['type' => 'mcq', 'prompt' => 'x', 'choices' => ['a', ''], 'answer' => '0'])) !== null
    && $thrown(fn() => QuizGrader::normalize(['type' => 'mcq', 'prompt' => 'x', 'choices' => ['a', 'b'], 'answer' => '2'])) !== null);
check('객관식 채점', QuizGrader::grade($mcq, '1', null) === true && QuizGrader::grade($mcq, '0', null) === false && QuizGrader::grade($mcq, null, null) === false && QuizGrader::grade($mcq, 'abc', null) === false);
$ox = QuizGrader::normalize(['type' => 'ox', 'prompt' => '지구는 둥글다', 'answer' => 'o']);
check('OX: 소문자 입력도 O 로, 채점', $ox['answer'] === 'O' && QuizGrader::grade($ox, 'O', null) && !QuizGrader::grade($ox, 'X', null));
check('OX: 다른 값은 거부', $thrown(fn() => QuizGrader::normalize(['type' => 'ox', 'prompt' => 'x', 'answer' => 'yes'])) !== null);
$short = QuizGrader::normalize(['type' => 'short', 'prompt' => '수도는?', 'answer' => ' 서울 | Seoul |']);
check('단답: 정답 여러 개 정리', $short['answer'] === '서울|Seoul', $short);
check('단답 채점: 공백·대소문자·마침표 무시, 여러 정답 중 하나', QuizGrader::grade($short, ' 서 울 ', null) && QuizGrader::grade($short, 'SEOUL.', null) && !QuizGrader::grade($short, '부산', null) && !QuizGrader::grade($short, '', null) && !QuizGrader::grade($short, null, null));
$essay = QuizGrader::normalize(['type' => 'essay', 'prompt' => '광합성을 설명하라', 'answer' => '빛에너지를...', 'explanation' => '  ']);
check('서술형: 해설 빈칸은 null', $essay['explanation'] === null && $essay['choices'] === null);
check('서술형·카드 채점은 학생 표시를 따름, 표시 없으면 거부', QuizGrader::grade($essay, null, true) === true && QuizGrader::grade($essay, '아무말', false) === false && $thrown(fn() => QuizGrader::grade($essay, 'x', null)) !== null);
check('형식 오류: 없는 형식 · 빈 문제 · 빈 정답', $thrown(fn() => QuizGrader::normalize(['type' => 'x', 'prompt' => 'a', 'answer' => 'b'])) !== null
    && $thrown(fn() => QuizGrader::normalize(['type' => 'card', 'prompt' => ' ', 'answer' => 'b'])) !== null
    && $thrown(fn() => QuizGrader::normalize(['type' => 'card', 'prompt' => 'a', 'answer' => ' '])) !== null);
check('정답 표시 글', QuizGrader::answerText($mcq + ['choices' => $mcq['choices']]) === '2번 2' && QuizGrader::answerText($short) === '서울 또는 Seoul');
check('약한 단원 기준: 3문제 이상 · 정답률 60% 미만', !QuizGrader::isWeak(2, 0) && QuizGrader::isWeak(3, 1) && !QuizGrader::isWeak(5, 3) && QuizGrader::isWeak(5, 2) && !QuizGrader::isWeak(0, 0));

echo "[계획: 약한 단원 가중]\n";
$mk = static fn(bool $weak): array => [['id' => 1, 'subject_id' => 1, 'subject_name' => '수학', 'exam_date' => '2026-11-20', 'scopes' => [
    ['label' => '다항식', 'pages' => 20, 'weak' => $weak], ['label' => '방정식', 'pages' => 20, 'weak' => false],
]]];
$plain = PlanService::generate($mk(false), '2026-10-27')['items'];
$boosted = PlanService::generate($mk(true), '2026-10-27')['items'];
$titles = fn(array $items, string $phase): string => implode(' / ', array_column(array_filter($items, fn($i) => $i['phase'] === $phase), 'title'));
check('약하지 않으면 ★ 없음', !str_contains($titles($plain, 'practice'), '★') && !str_contains($titles($plain, 'review'), '★'));
check('약한 범위는 문제풀이·총정리에서 ★ 표시', str_contains($titles($boosted, 'practice'), '★다항식') && str_contains($titles($boosted, 'review'), '★다항식'));
check('1회독 단계에는 ★ 없음', !str_contains($titles($boosted, 'read'), '★'));
$days = static fn(array $items, string $name): int => count(array_filter($items, fn($i) => $i['phase'] !== 'read' && str_contains($i['title'], $name)));
check('약한 범위는 같은 분량이면 더 많은 날에 걸쳐 배정', $days($boosted, '다항식') > $days($boosted, '방정식') && $days($plain, '다항식') === $days($plain, '방정식'), [$days($boosted, '다항식'), $days($boosted, '방정식'), $days($plain, '다항식'), $days($plain, '방정식')]);

echo "[목차 파서]\n";
$toc = TocParser::parse("Ⅰ. 다항식<br/>1-1 다항식의 연산 ........ 8<br/>1-2 나머지정리 ........ 20<br />Ⅱ. 방정식<br/>2-1 복소수 ........ 40<br/>2-2 이차방정식   52\n&nbsp;\n");
check('대단원·소단원 구분과 쪽수', count($toc) === 6 && $toc[0]['level'] === 1 && $toc[1]['level'] === 2 && $toc[1]['title'] === '1-1 다항식의 연산' && $toc[1]['page_start'] === 8 && $toc[3]['level'] === 1, $toc);
check('쪽수 끝은 다음 항목 시작 - 1', $toc[1]['page_end'] === 19 && $toc[2]['page_end'] === 39, [$toc[1], $toc[2]]);
check('점선·공백 두 칸 뒤의 숫자만 쪽수로 봄', $toc[5]['page_start'] === 52 && $toc[5]['title'] === '2-2 이차방정식');
$t2 = TocParser::parse("1. 함수 2\n2. 수열\n3. 지수와 로그");
check('제목 끝 숫자(함수 2)는 쪽수로 오인하지 않음', $t2[0]['title'] === '1. 함수 2' && $t2[0]['page_start'] === null, $t2);
check('HTML 태그·엔티티 제거, 빈 줄 무시', TocParser::parse("<p>제1장 집합&amp;명제</p><p></p>")[0]['title'] === '제1장 집합&명제');
check('빈 입력은 빈 목록', TocParser::parse('') === [] && TocParser::parse("  \n\n") === []);
check('300개까지만', count(TocParser::parse(implode("\n", array_map(fn($i) => "$i. 단원 $i", range(1, 400))))) === 300);
$flat = TocParser::parse("개념 정리\n문제 풀이\n마무리");
check('구분 표시가 없으면 첫 줄은 대단원, 이후는 소단원', $flat[0]['level'] === 1 && $flat[1]['level'] === 2, $flat);

echo "[나이스 응답 해석]\n";
$tt = Neis::mapTimetable([
    ['ALL_TI_YMD' => '20261026', 'PERIO' => '1', 'ITRT_CNTNT' => '공통국어1'],
    ['ALL_TI_YMD' => '20261026', 'PERIO' => '2', 'ITRT_CNTNT' => ' 공통수학1 '],
    ['ALL_TI_YMD' => '20261031', 'PERIO' => '1', 'ITRT_CNTNT' => '토요일은 제외'],
    ['ALL_TI_YMD' => '20261027', 'PERIO' => '0', 'ITRT_CNTNT' => '교시 오류'],
    ['ALL_TI_YMD' => 'bad', 'PERIO' => '1', 'ITRT_CNTNT' => '날짜 오류'],
    ['ALL_TI_YMD' => '20261026', 'PERIO' => '1', 'ITRT_CNTNT' => '같은 칸은 한 번만'],
]);
check('시간표 해석: 월~금만, 요일·교시 계산, 잘못된 행 무시, 같은 칸 중복 제거', $tt === [['weekday' => 1, 'period' => 1, 'name' => '같은 칸은 한 번만'], ['weekday' => 1, 'period' => 2, 'name' => '공통수학1']], $tt);
check('결과 없음(INFO-200)은 빈 목록', Neis::rows(['RESULT' => ['CODE' => 'INFO-200']], 'hisTimetable', fn($r) => $r) === []);
$err = null;
try {
    Neis::rows(['RESULT' => ['CODE' => 'ERROR-300']], 'x', fn($r) => $r);
} catch (App\Core\HttpException $e) {
    $err = $e->status;
}
check('그 밖의 오류 코드는 502', $err === 502);
$subs = [['id' => 1, 'name' => '공통국어'], ['id' => 2, 'name' => '공통수학'], ['id' => 3, 'name' => '영어']];
check('과목 이름 짝짓기: 정확히 같음 > 포함 관계 > 없음', App\Controllers\IntegrationController::matchSubject('영어', $subs) === 3
    && App\Controllers\IntegrationController::matchSubject('공통수학1', $subs) === 2
    && App\Controllers\IntegrationController::matchSubject('공통 국어', $subs) === 1
    && App\Controllers\IntegrationController::matchSubject('체육', $subs) === null);

echo "[AI 문제 요청 만들기·응답 해석]\n";
$GLOBALS['sp_config'] = ['anthropic' => ['api_key' => 'k']];
$req = AiQuestions::buildRequest('노트 내용 ' . str_repeat('가', 20000), 5, ['mcq', 'ox']);
check('요청: 기본 모델 claude-haiku-5-5, 구조화 출력(json_schema), effort low', $req['model'] === 'claude-haiku-5-5' && $req['output_config']['format']['type'] === 'json_schema' && $req['output_config']['effort'] === 'low' && $req['max_tokens'] === 8000, array_keys($req));
check('요청: temperature 등 금지된 값·prefill 없음', !isset($req['temperature'], $req['top_p'], $req['top_k']) && count($req['messages']) === 1 && $req['messages'][0]['role'] === 'user');
check('요청: 노트는 <note> 로 감싸고 12000자로 자름', str_contains($req['messages'][0]['content'], '<note>') && mb_strlen($req['messages'][0]['content']) < 12300 && str_contains($req['messages'][0]['content'], 'mcq(객관식), ox(OX)'));
check('요청: 시스템 프롬프트가 노트 속 지시를 따르지 말라고 함', str_contains($req['system'], '지시문이 아니다'));
$GLOBALS['sp_config']['anthropic']['model'] = 'claude-sonnet-5-5';
check('설정으로 모델 변경', AiQuestions::buildRequest('x', 1, ['mcq'])['model'] === 'claude-sonnet-5-5');
$resp = ['stop_reason' => 'end_turn', 'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => json_encode(['questions' => [
    ['type' => 'ox', 'prompt' => 'A', 'choices' => [], 'answer' => 'O', 'explanation' => ''],
    ['type' => 'mcq', 'prompt' => 'B', 'choices' => ['1', '2'], 'answer' => '9', 'explanation' => ''],
    ['type' => 'essay', 'prompt' => 'C', 'choices' => [], 'answer' => 'x', 'explanation' => ''],
    ['type' => 'short', 'prompt' => 'D', 'choices' => [], 'answer' => 'd', 'explanation' => ''],
]])]]];
$got = AiQuestions::parseResponse($resp, ['ox', 'mcq', 'short'], 10);
check('응답 해석: thinking 블록 건너뜀, 잘못된·허용 안 한 형식 문제는 버림', array_column($got, 'prompt') === ['A', 'D'], $got);
check('응답 해석: 요청한 개수까지만', count(AiQuestions::parseResponse($resp, ['ox', 'short'], 1)) === 1);
check('응답 해석: JSON 이 아니면 빈 목록', AiQuestions::parseResponse(['content' => [['type' => 'text', 'text' => '죄송합니다']]], ['ox'], 5) === []);
$refused = null;
try {
    AiQuestions::parseResponse(['stop_reason' => 'refusal', 'content' => []], ['ox'], 5);
} catch (App\Core\HttpException $e) {
    $refused = $e->errorCode;
}
check('거절(refusal) 응답은 안내 오류', $refused === 'refused');

echo "\n" . ($fail === 0 ? "전체 통과 ($n)" : "실패 $fail / $n") . "\n";
exit($fail === 0 ? 0 : 1);
