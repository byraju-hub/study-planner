<?php
declare(strict_types=1);

namespace App\Services;

/** 수행평가 종류별 기본 단계와, 오늘부터 마감일까지 고르게 나눈 목표일. DB를 쓰지 않는 순수 함수. */
final class AssessmentPlan
{
    public const KINDS = [
        'report' => '보고서',
        'presentation' => '발표',
        'experiment' => '실험',
        'written' => '서술형',
        'portfolio' => '포트폴리오',
        'practical' => '실기',
        'etc' => '기타',
    ];

    private const STEPS = [
        'report' => ['주제 정하기', '자료 조사', '초안 작성', '수정 · 보완', '제출'],
        'presentation' => ['주제 정하기', '자료 조사', '발표 자료 만들기', '발표 연습', '최종 점검'],
        'experiment' => ['실험 계획', '실험 진행', '결과 정리', '보고서 작성', '제출'],
        'written' => ['범위 정리', '예상 문제 풀이', '오답 정리', '최종 점검'],
        'portfolio' => ['자료 모으기', '정리 · 편집', '점검', '제출'],
        'practical' => ['연습 계획', '연습', '최종 점검'],
        'etc' => ['준비', '진행', '제출'],
    ];

    /** @return list<array{title:string,target_date:string}> */
    public static function steps(string $kind, string $today, string $due): array
    {
        $titles = self::STEPS[$kind] ?? self::STEPS['etc'];
        $n = count($titles);
        $start = new \DateTimeImmutable($today);
        $end = new \DateTimeImmutable($due);
        $total = max(0, (int) $start->diff($end)->format('%r%a'));

        $out = [];
        foreach ($titles as $i => $title) {
            if ($end <= $start) {
                $date = $end;
            } else {
                $offset = $n === 1 ? $total : (int) round($i * $total / ($n - 1));
                $date = $start->modify("+$offset days");
            }
            $out[] = ['title' => $title, 'target_date' => $date->format('Y-m-d')];
        }
        return $out;
    }
}
