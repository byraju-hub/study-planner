<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;
use App\Services\QuizGrader;
use App\Services\QuizStats;

final class QuizController
{
    private const MAX_COUNT = 50;

    /**
     * 퀴즈 시작. mode: mixed(틀린 것·안 푼 것 먼저) | wrong(오답노트만) | new(안 푼 것만)
     * 정답은 내려보내지 않는다. 채점은 서버가 한다.
     */
    public static function start(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $subjectId = Input::ownedSubject($b, 'subject_id', $uid);
        $unitId = isset($b['unit_id']) && $b['unit_id'] !== '' && $b['unit_id'] !== null ? (int) Owned::find('sp_units', (int) $b['unit_id'], $uid)['id'] : null;
        $count = Input::int($b, 'count', 1, self::MAX_COUNT, '문제 수', false) ?? 10;
        $mode = Input::enum($b, 'mode', ['mixed', 'wrong', 'new'], '방식', 'mixed');

        $sql = 'SELECT q.id, q.subject_id, q.unit_id, q.type, q.prompt, q.choices,
                       (SELECT a.is_correct FROM sp_quiz_answers a WHERE a.user_id = q.user_id AND a.question_id = q.id ORDER BY a.id DESC LIMIT 1) AS last_correct,
                       (SELECT MAX(a.id) FROM sp_quiz_answers a WHERE a.user_id = q.user_id AND a.question_id = q.id) AS last_answer_id
                  FROM sp_questions q WHERE q.user_id = ?';
        $params = [$uid];
        if ($subjectId !== null) {
            $sql .= ' AND q.subject_id = ?';
            $params[] = $subjectId;
        }
        if ($unitId !== null) {
            $sql .= ' AND q.unit_id = ?';
            $params[] = $unitId;
        }
        if (!empty($b['types']) && is_array($b['types'])) {
            $types = array_values(array_intersect(array_keys(QuizGrader::TYPES), $b['types']));
            if ($types !== []) {
                $sql .= ' AND q.type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
                array_push($params, ...$types);
            }
        }
        $pool = Db::all($sql, $params);
        if ($mode === 'wrong') {
            $pool = array_values(array_filter($pool, static fn(array $r): bool => $r['last_correct'] !== null && (int) $r['last_correct'] === 0));
        } elseif ($mode === 'new') {
            $pool = array_values(array_filter($pool, static fn(array $r): bool => $r['last_correct'] === null));
        }
        if ($pool === []) {
            throw new HttpException(422, 'validation', match ($mode) {
                'wrong' => '오답노트가 비어 있어요. 🎉',
                'new' => '아직 풀지 않은 문제가 없어요.',
                default => '이 범위에 문제가 없어요. 먼저 문제를 만들어 주세요.',
            });
        }

        // 우선순위: 틀린 문제 → 안 푼 문제 → 맞힌 문제(오래된 순). 같은 순위 안에서는 섞는다.
        shuffle($pool);
        usort($pool, static function (array $a, array $b): int {
            $rank = static fn(array $r): int => $r['last_correct'] === null ? 1 : ((int) $r['last_correct'] === 0 ? 0 : 2);
            return [$rank($a), $rank($a) === 2 ? (int) $a['last_answer_id'] : 0] <=> [$rank($b), $rank($b) === 2 ? (int) $b['last_answer_id'] : 0];
        });
        $picked = array_slice($pool, 0, $count);
        shuffle($picked);

        $attemptId = Db::insert(
            'INSERT INTO sp_quiz_attempts (user_id, subject_id, unit_id, total) VALUES (?, ?, ?, ?)',
            [$uid, $subjectId, $unitId, count($picked)]
        );
        return [
            'attempt_id' => $attemptId,
            'questions' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'prompt' => $r['prompt'],
                'choices' => $r['choices'] === null ? null : json_decode($r['choices'], true),
            ], $picked),
        ];
    }

    public static function answer(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $attempt = self::attempt($uid, $p['id']);
        if ($attempt['finished_at'] !== null) {
            throw new HttpException(409, 'conflict', '이미 끝난 퀴즈예요.');
        }
        $b = $req->body;
        $qid = (int) Input::int($b, 'question_id', 1, PHP_INT_MAX, '문제');
        $q = QuestionController::present(Owned::find('sp_questions', $qid, $uid));
        if (Db::one('SELECT id FROM sp_quiz_answers WHERE attempt_id = ? AND question_id = ?', [$attempt['id'], $qid]) !== null) {
            throw new HttpException(409, 'conflict', '이미 답한 문제예요.');
        }
        $given = isset($b['given']) ? trim((string) $b['given']) : null;
        $self = array_key_exists('self_correct', $b) ? (bool) $b['self_correct'] : null;
        try {
            $correct = QuizGrader::grade($q, $given, $self);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, 'validation', $e->getMessage());
        }

        Db::run(
            'INSERT INTO sp_quiz_answers (user_id, attempt_id, question_id, given, is_correct) VALUES (?, ?, ?, ?, ?)',
            [$uid, $attempt['id'], $qid, $given === null ? null : mb_substr($given, 0, 4000), (int) $correct]
        );
        if (!$correct) {
            self::queueReview($uid, $q);
        }
        return [
            'is_correct' => $correct,
            'answer' => $q['answer'],
            'answer_text' => QuizGrader::answerText($q),
            'explanation' => $q['explanation'],
        ];
    }

    public static function finish(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $attempt = self::attempt($uid, $p['id']);
        $row = Db::one('SELECT COUNT(*) AS n, COALESCE(SUM(is_correct), 0) AS c FROM sp_quiz_answers WHERE attempt_id = ?', [$attempt['id']]);
        Db::run(
            'UPDATE sp_quiz_attempts SET finished_at = COALESCE(finished_at, NOW()), total = ?, correct = ? WHERE id = ? AND user_id = ?',
            [(int) $row['n'], (int) $row['c'], $attempt['id'], $uid]
        );
        $wrong = Db::all(
            'SELECT q.id, q.prompt, q.type, q.choices, q.answer, q.explanation, a.given
               FROM sp_quiz_answers a JOIN sp_questions q ON q.id = a.question_id
              WHERE a.attempt_id = ? AND a.is_correct = 0 ORDER BY a.id',
            [$attempt['id']]
        );
        return [
            'total' => (int) $row['n'],
            'correct' => (int) $row['c'],
            'wrong' => array_map(static fn(array $w): array => QuestionController::present($w) + ['answer_text' => QuizGrader::answerText(QuestionController::present($w))], $wrong),
        ];
    }

    public static function attempts(Request $req): array
    {
        $uid = Session::requireUser();
        return Db::all(
            'SELECT t.id, t.started_at, t.finished_at, t.total, t.correct, s.name AS subject_name, u.title AS unit_title
               FROM sp_quiz_attempts t LEFT JOIN sp_subjects s ON s.id = t.subject_id LEFT JOIN sp_units u ON u.id = t.unit_id
              WHERE t.user_id = ? AND t.finished_at IS NOT NULL ORDER BY t.id DESC LIMIT 20',
            [$uid]
        );
    }

    public static function stats(Request $req): array
    {
        return QuizStats::summary(Session::requireUser());
    }

    /** 가장 최근에 틀린 문제들. 다시 맞히면 자동으로 빠진다. */
    public static function wrongNotes(Request $req): array
    {
        $uid = Session::requireUser();
        $subjectId = Input::ownedSubject($req->query, 'subject_id', $uid);
        $ids = QuizStats::wrongQuestionIds($uid, $subjectId);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all(
            "SELECT q.id, q.subject_id, q.unit_id, q.type, q.prompt, q.choices, q.answer, q.explanation, s.name AS subject_name, u.title AS unit_title,
                    (SELECT a.given FROM sp_quiz_answers a WHERE a.question_id = q.id ORDER BY a.id DESC LIMIT 1) AS last_given,
                    (SELECT COUNT(*) FROM sp_quiz_answers a WHERE a.question_id = q.id AND a.is_correct = 0) AS wrong_count
               FROM sp_questions q JOIN sp_subjects s ON s.id = q.subject_id LEFT JOIN sp_units u ON u.id = q.unit_id
              WHERE q.user_id = ? AND q.id IN ($in) ORDER BY q.id DESC",
            [$uid, ...$ids]
        );
        return array_map(static function (array $r): array {
            $q = QuestionController::present($r);
            $q['wrong_count'] = (int) $r['wrong_count'];
            $q['answer_text'] = QuizGrader::answerText($q);
            return $q;
        }, $rows);
    }

    /** 틀린 문제는 하루 뒤 복습 목록에 올린다. 같은 문제의 복습이 이미 있으면 만들지 않는다. */
    private static function queueReview(int $uid, array $q): void
    {
        if (Db::one('SELECT id FROM sp_review_items WHERE user_id = ? AND question_id = ?', [$uid, $q['id']]) !== null) {
            return;
        }
        $title = '오답: ' . mb_substr(preg_replace('/\s+/u', ' ', $q['prompt']), 0, 40);
        Db::run(
            'INSERT INTO sp_review_items (user_id, subject_id, unit_id, question_id, title, stage, next_review_date) VALUES (?, ?, ?, ?, ?, 1, ?)',
            [$uid, $q['subject_id'], $q['unit_id'], $q['id'], $title, ReviewController::addDays(date('Y-m-d'), 1)]
        );
    }

    /** @return array<string,mixed> */
    private static function attempt(int $uid, int $id): array
    {
        $row = Db::one('SELECT id, finished_at FROM sp_quiz_attempts WHERE id = ? AND user_id = ?', [$id, $uid]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', '찾을 수 없어요.');
        }
        return $row;
    }
}
