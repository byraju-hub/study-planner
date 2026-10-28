<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/** 문제별로 가장 최근 답만 본 정답률. 한 번 틀렸어도 나중에 맞히면 "맞힌 문제"로 센다. */
final class QuizStats
{
    private const LATEST = 'sp_quiz_answers a JOIN (SELECT question_id, MAX(id) AS mid FROM sp_quiz_answers WHERE user_id = ? GROUP BY question_id) m ON a.id = m.mid';

    /** @return array{overall:array<string,mixed>,subjects:list<array<string,mixed>>,units:list<array<string,mixed>>,weak_units:list<array<string,mixed>>} */
    public static function summary(int $userId): array
    {
        $units = Db::all(
            'SELECT q.subject_id, q.unit_id, u.title AS unit_title, s.name AS subject_name, s.color,
                    COUNT(*) AS questions, COUNT(a.id) AS answered, COALESCE(SUM(a.is_correct), 0) AS correct
               FROM sp_questions q
               JOIN sp_subjects s ON s.id = q.subject_id
               LEFT JOIN sp_units u ON u.id = q.unit_id
               LEFT JOIN (SELECT a.id, a.question_id, a.is_correct FROM ' . self::LATEST . ') a ON a.question_id = q.id
              WHERE q.user_id = ?
              GROUP BY q.subject_id, q.unit_id, u.title, s.name, s.color
              ORDER BY s.sort_order, s.id, u.sort_order, u.id',
            [$userId, $userId]
        );

        $subjects = [];
        $totalAnswered = $totalCorrect = $totalQuestions = 0;
        $outUnits = [];
        foreach ($units as $r) {
            $answered = (int) $r['answered'];
            $correct = (int) $r['correct'];
            $sid = (int) $r['subject_id'];
            $subjects[$sid] ??= ['subject_id' => $sid, 'subject_name' => $r['subject_name'], 'color' => $r['color'], 'questions' => 0, 'answered' => 0, 'correct' => 0];
            $subjects[$sid]['questions'] += (int) $r['questions'];
            $subjects[$sid]['answered'] += $answered;
            $subjects[$sid]['correct'] += $correct;
            $totalQuestions += (int) $r['questions'];
            $totalAnswered += $answered;
            $totalCorrect += $correct;
            $outUnits[] = [
                'subject_id' => $sid,
                'subject_name' => $r['subject_name'],
                'unit_id' => $r['unit_id'] === null ? null : (int) $r['unit_id'],
                'unit_title' => $r['unit_title'] ?? '단원 없음',
                'questions' => (int) $r['questions'],
                'answered' => $answered,
                'correct' => $correct,
                'accuracy' => $answered > 0 ? round($correct / $answered, 3) : null,
                'weak' => $r['unit_id'] !== null && QuizGrader::isWeak($answered, $correct),
            ];
        }
        $outSubjects = array_values(array_map(static fn(array $s): array => $s + ['accuracy' => $s['answered'] > 0 ? round($s['correct'] / $s['answered'], 3) : null], $subjects));

        return [
            'overall' => [
                'questions' => $totalQuestions,
                'answered' => $totalAnswered,
                'correct' => $totalCorrect,
                'accuracy' => $totalAnswered > 0 ? round($totalCorrect / $totalAnswered, 3) : null,
            ],
            'subjects' => $outSubjects,
            'units' => $outUnits,
            'weak_units' => array_values(array_filter($outUnits, static fn(array $u): bool => $u['weak'])),
        ];
    }

    /** @return list<int> */
    public static function weakUnitIds(int $userId): array
    {
        return array_map(static fn(array $u): int => (int) $u['unit_id'], self::summary($userId)['weak_units']);
    }

    /** 가장 최근 답이 오답인 문제 id 들 (오답노트). @return list<int> */
    public static function wrongQuestionIds(int $userId, ?int $subjectId = null, ?int $unitId = null): array
    {
        $sql = 'SELECT q.id FROM sp_questions q JOIN (SELECT a.question_id, a.is_correct FROM ' . self::LATEST . ') a ON a.question_id = q.id
                 WHERE q.user_id = ? AND a.is_correct = 0';
        $params = [$userId, $userId];
        if ($subjectId !== null) {
            $sql .= ' AND q.subject_id = ?';
            $params[] = $subjectId;
        }
        if ($unitId !== null) {
            $sql .= ' AND q.unit_id = ?';
            $params[] = $unitId;
        }
        return array_map(static fn(array $r): int => (int) $r['id'], Db::all($sql . ' ORDER BY q.id', $params));
    }
}
