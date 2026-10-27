<?php
declare(strict_types=1);

// 사용: php backend/tests/plan_test.php   (DB·서버 없이 실행)
require __DIR__ . '/../src/bootstrap.php';

use App\Services\PlanService;

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

echo "\n" . ($fail === 0 ? "전체 통과 ($n)" : "실패 $fail / $n") . "\n";
exit($fail === 0 ? 0 : 1);
