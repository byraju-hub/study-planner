<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 시험 대비 계획 계산. DB를 쓰지 않는 순수 함수라서 따로 테스트할 수 있다.
 *
 * 오늘부터 시험 전날까지의 날짜를 세 구간으로 나눈다.
 *   1회독(약 60%) → 문제풀이(나머지) → 총정리(약 15%, 최소 하루)
 * 시험 범위(단원)는 쪽수에 비례해서 구간 안의 날짜에 배분한다.
 * 같은 과목·같은 날·같은 단계의 범위는 할 일 하나로 합친다.
 */
final class PlanService
{
    public const PHASE_LABELS = ['read' => '범위 1회독', 'practice' => '문제풀이', 'review' => '총정리'];

    private const DEFAULT_WEIGHT = 10;

    /**
     * @param list<array{id:int,subject_id:int,subject_name:string,exam_date:string,scopes:list<array{label:string,pages:?int}>}> $subjects
     * @return array{items:list<array<string,mixed>>,skipped:list<array{exam_subject_id:int,subject_name:string,reason:string}>}
     */
    public static function generate(array $subjects, string $today): array
    {
        $items = [];
        $skipped = [];

        foreach ($subjects as $s) {
            $days = self::daysBetween($today, $s['exam_date']);
            if (count($days) === 0) {
                $skipped[] = [
                    'exam_subject_id' => $s['id'],
                    'subject_name' => $s['subject_name'],
                    'reason' => $s['exam_date'] <= $today ? '시험일이 오늘이거나 이미 지났어요.' : '계획을 세울 날이 없어요.',
                ];
                continue;
            }

            [$nRead, $nPractice, $nReview] = self::phaseCounts(count($days));
            $phases = [
                'read' => array_slice($days, 0, $nRead),
                'practice' => array_slice($days, $nRead, $nPractice),
                'review' => array_slice($days, $nRead + $nPractice, $nReview),
            ];

            foreach ($phases as $phase => $phaseDays) {
                if (count($phaseDays) === 0) {
                    continue;
                }
                foreach (self::phaseItems($s, $phase, $phaseDays) as $item) {
                    $items[] = $item;
                }
            }
        }

        usort($items, static fn(array $a, array $b): int => [$a['task_date'], $a['exam_subject_id']] <=> [$b['task_date'], $b['exam_subject_id']]);
        return ['items' => $items, 'skipped' => $skipped];
    }

    /** @return array{0:int,1:int,2:int} [1회독, 문제풀이, 총정리] 일수 */
    public static function phaseCounts(int $n): array
    {
        if ($n <= 0) {
            return [0, 0, 0];
        }
        if ($n === 1) {
            return [0, 0, 1];
        }
        if ($n === 2) {
            return [0, 1, 1];
        }
        $read = max(1, (int) round($n * 0.6));
        $review = max(1, (int) round($n * 0.15));
        $practice = $n - $read - $review;
        if ($practice < 1) {
            $read -= 1 - $practice;
            $practice = 1;
        }
        return [$read, $practice, $review];
    }

    /**
     * 오늘부터 시험 전날까지 (양 끝 포함). 시험일이 오늘 이전이면 빈 배열.
     * @return list<string>
     */
    public static function daysBetween(string $today, string $examDate): array
    {
        $days = [];
        $d = new \DateTimeImmutable($today);
        $last = (new \DateTimeImmutable($examDate))->modify('-1 day');
        while ($d <= $last && count($days) < 366) {
            $days[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        return $days;
    }

    /**
     * @param array<string,mixed> $s
     * @param list<string> $days
     * @return list<array<string,mixed>>
     */
    private static function phaseItems(array $s, string $phase, array $days): array
    {
        $scopes = $s['scopes'];
        $out = [];
        $label = self::PHASE_LABELS[$phase];

        if (count($scopes) === 0) {
            foreach ($days as $i => $day) {
                $suffix = count($days) > 1 ? ' (' . ($i + 1) . '/' . count($days) . ')' : '';
                $out[] = self::item($s, $phase, $day, "{$s['subject_name']} {$label}{$suffix}", self::minutes($phase, null));
            }
            return $out;
        }

        $perDay = self::distribute($scopes, count($days));
        foreach ($perDay as $dayIdx => $parts) {
            if (count($parts) === 0) {
                continue;
            }
            $names = [];
            $pages = 0.0;
            $known = false;
            foreach ($parts as $part) {
                $sc = $scopes[$part['scope']];
                $names[] = $sc['label'] . ($part['parts'] > 1 ? " ({$part['part']}/{$part['parts']})" : '');
                if ($sc['pages'] !== null) {
                    $pages += $sc['pages'] / $part['parts'];
                    $known = true;
                }
            }
            $title = "{$s['subject_name']} {$label} · " . implode(', ', $names);
            $out[] = self::item($s, $phase, $days[$dayIdx], $title, self::minutes($phase, $known ? (int) round($pages) : null, count($parts)));
        }
        return $out;
    }

    /**
     * 범위를 쪽수 비례로 날짜에 나눈다. 큰 범위는 여러 날에 걸쳐 "(1/3)"처럼 쪼개고,
     * 작은 범위는 중간 지점이 속한 하루에 넣는다. 범위가 날짜보다 적어도 빈 날이 생기지 않는다.
     *
     * @param list<array{label:string,pages:?int}> $scopes
     * @return array<int,list<array{scope:int,part:int,parts:int}>> 날짜 인덱스 → 그날 할 범위들
     */
    private static function distribute(array $scopes, int $dayCount): array
    {
        $weights = array_map(static fn(array $sc): int => max(1, $sc['pages'] ?? self::DEFAULT_WEIGHT), $scopes);
        $total = array_sum($weights);
        $len = $total / $dayCount;
        $result = array_fill(0, $dayCount, []);
        $before = 0;
        foreach ($weights as $i => $w) {
            $a = $before;
            $b = $before + $w;
            $days = [];
            for ($d = 0; $d < $dayCount; $d++) {
                $overlap = min($b, ($d + 1) * $len) - max($a, $d * $len);
                if ($overlap >= $len * 0.5 - 1e-9) {
                    $days[] = $d;
                }
            }
            if (count($days) === 0) {
                $days[] = min($dayCount - 1, (int) floor((($a + $b) / 2) / $len));
            }
            foreach ($days as $k => $d) {
                $result[$d][] = ['scope' => $i, 'part' => $k + 1, 'parts' => count($days)];
            }
            $before = $b;
        }
        return $result;
    }

    private static function minutes(string $phase, ?int $pages, int $scopeCount = 1): int
    {
        $base = match ($phase) {
            'read' => $pages !== null ? max(20, min(90, $pages * 2)) : 40,
            'practice' => 40,
            default => 30,
        };
        return min(180, $phase === 'read' ? $base : $base * max(1, $scopeCount));
    }

    /** @return array<string,mixed> */
    private static function item(array $s, string $phase, string $day, string $title, int $minutes): array
    {
        return [
            'exam_subject_id' => $s['id'],
            'subject_id' => $s['subject_id'],
            'subject_name' => $s['subject_name'],
            'phase' => $phase,
            'task_date' => $day,
            'title' => mb_strlen($title) > 120 ? mb_substr($title, 0, 119) . '…' : $title,
            'est_minutes' => $minutes,
        ];
    }
}
