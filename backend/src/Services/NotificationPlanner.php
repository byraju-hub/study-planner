<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 지금 시각과 데이터로 "만들어야 할 알림" 목록을 계산한다. DB를 쓰지 않는 순수 함수.
 * 같은 dedupe_key 는 한 번만 저장되므로, 여러 번 불러도 알림이 중복되지 않는다.
 */
final class NotificationPlanner
{
    public const DEFAULT_SETTINGS = [
        'tasks_on' => true,
        'review_on' => true,
        'exam_on' => true,
        'assessment_on' => true,
        'morning_time' => '07:30',
        'evening_time' => '21:00',
    ];

    private const EXAM_DAYS = [7, 3, 1, 0];
    private const ASSESSMENT_DAYS = [3, 1, 0];
    private const ASSESSMENT_LATE_DAYS = 3;

    /**
     * @param array{
     *   today:string, time:string, settings:array<string,mixed>,
     *   tasks:list<array{title:string,done:bool,est_minutes:?int}>,
     *   reviews:list<string>,
     *   exams:list<array{id:int,title:string,start_date:string}>,
     *   assessments:list<array{id:int,title:string,due_date:string,status:string}>
     * } $in
     * @return list<array{kind:string,title:string,body:?string,link:string,dedupe_key:string}>
     */
    public static function plan(array $in): array
    {
        $s = array_merge(self::DEFAULT_SETTINGS, $in['settings']);
        $today = $in['today'];
        $morning = $in['time'] >= (string) $s['morning_time'];
        $out = [];

        $tasks = $in['tasks'];
        $undone = array_values(array_filter($tasks, static fn(array $t): bool => !$t['done']));

        if ($s['tasks_on'] && $morning && $tasks !== []) {
            $minutes = array_sum(array_map(static fn(array $t): int => (int) ($t['est_minutes'] ?? 0), $undone));
            $body = '첫 번째: ' . $tasks[0]['title'] . ($minutes > 0 ? ' · 예상 ' . self::minutes($minutes) : '');
            $out[] = self::n('tasks', '오늘 할 일 ' . count($tasks) . '개', $body, '/', "morning:$today");
        }

        if ($s['tasks_on'] && $s['evening_time'] !== null && $in['time'] >= (string) $s['evening_time'] && $undone !== []) {
            $out[] = self::n('tasks', '아직 ' . count($undone) . '개 남았어요', '오늘 할 일 중 ' . $undone[0]['title'] . ' 등이 남아 있어요.', '/', "evening:$today");
        }

        if ($s['review_on'] && $morning && $in['reviews'] !== []) {
            $count = count($in['reviews']);
            $out[] = self::n('review', "복습할 내용 {$count}개", implode(', ', array_slice($in['reviews'], 0, 3)) . ($count > 3 ? ' 외' : ''), '/study', "review:$today");
        }

        if ($s['exam_on'] && $morning) {
            foreach ($in['exams'] as $e) {
                $d = self::daysUntil($today, $e['start_date']);
                if (!in_array($d, self::EXAM_DAYS, true)) {
                    continue;
                }
                $title = $d === 0 ? "오늘은 {$e['title']} 첫날이에요" : "{$e['title']} D-$d";
                $body = $d === 0 ? '준비한 만큼 차분히 풀고 와요.' : '시험 대비 계획을 확인해 보세요.';
                $out[] = self::n('exam', $title, $body, '/exams', "exam:{$e['id']}:$d");
            }
        }

        if ($s['assessment_on'] && $morning) {
            foreach ($in['assessments'] as $a) {
                if (!in_array($a['status'], ['planned', 'in_progress'], true)) {
                    continue;
                }
                $d = self::daysUntil($today, $a['due_date']);
                if (in_array($d, self::ASSESSMENT_DAYS, true)) {
                    $title = $d === 0 ? "{$a['title']} 마감이 오늘이에요" : "{$a['title']} 마감 D-$d";
                    $out[] = self::n('assessment', $title, '진행 상황을 확인해 보세요.', '/assessments', "assess:{$a['id']}:$d");
                } elseif ($d < 0 && -$d <= self::ASSESSMENT_LATE_DAYS) {
                    $out[] = self::n('assessment', "{$a['title']} 마감이 지났어요", '아직 제출 완료로 바꾸지 않았어요.', '/assessments', "assess:{$a['id']}:late:$today");
                }
            }
        }

        return $out;
    }

    public static function daysUntil(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    private static function minutes(int $m): string
    {
        return $m >= 60 ? intdiv($m, 60) . '시간' . ($m % 60 ? ' ' . $m % 60 . '분' : '') : $m . '분';
    }

    /** @return array{kind:string,title:string,body:?string,link:string,dedupe_key:string} */
    private static function n(string $kind, string $title, ?string $body, string $link, string $key): array
    {
        return ['kind' => $kind, 'title' => $title, 'body' => $body, 'link' => $link, 'dedupe_key' => $key];
    }
}
