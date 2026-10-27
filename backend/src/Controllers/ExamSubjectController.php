<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

/** 시험의 과목(시험일·교시·목표·결과)과 시험 범위. */
final class ExamSubjectController
{
    public static function add(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $exam = Owned::find('exams', $p['id'], $uid);
        $b = $req->body;
        $subjectId = (int) Input::ownedSubject($b, 'subject_id', $uid, true);
        $date = self::examDate($b, $exam, $exam['start_date']);
        $period = Input::int($b, 'period', 1, 10, '교시', false);
        [$score, $grade] = self::scoreGrade($b, 'target');

        try {
            Db::insert(
                'INSERT INTO exam_subjects (user_id, exam_id, subject_id, exam_date, period, target_score, target_grade)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$uid, $exam['id'], $subjectId, $date, $period, $score, $grade]
            );
        } catch (\PDOException $e) {
            throw Owned::isDuplicate($e)
                ? new HttpException(409, 'duplicate', '이미 이 시험에 들어 있는 과목이에요.', 'subject_id')
                : $e;
        }
        return ExamController::detail($uid, (int) $exam['id']);
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('exam_subjects', $p['id'], $uid);
        $exam = Owned::find('exams', (int) $row['exam_id'], $uid);
        $b = $req->body;

        $date = array_key_exists('exam_date', $b) ? self::examDate($b, $exam, $row['exam_date']) : $row['exam_date'];
        $period = array_key_exists('period', $b) ? Input::int($b, 'period', 1, 10, '교시', false) : $row['period'];
        $target = array_key_exists('target_score', $b) || array_key_exists('target_grade', $b)
            ? self::scoreGrade($b, 'target') : [$row['target_score'], $row['target_grade']];
        $actual = array_key_exists('actual_score', $b) || array_key_exists('actual_grade', $b)
            ? self::scoreGrade($b, 'actual') : [$row['actual_score'], $row['actual_grade']];

        Db::run(
            'UPDATE exam_subjects SET exam_date = ?, period = ?, target_score = ?, target_grade = ?, actual_score = ?, actual_grade = ?
              WHERE id = ? AND user_id = ?',
            [$date, $period, $target[0], $target[1], $actual[0], $actual[1], $row['id'], $uid]
        );
        return ExamController::detail($uid, (int) $row['exam_id']);
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('exam_subjects', $p['id'], $uid);
        Db::transaction(static function () use ($uid, $row): void {
            Db::run("DELETE FROM tasks WHERE user_id = ? AND source = 'exam' AND ref_id = ?", [$uid, $row['id']]);
            Db::run('DELETE FROM exam_subjects WHERE id = ? AND user_id = ?', [$row['id'], $uid]);
        });
        return ExamController::detail($uid, (int) $row['exam_id']);
    }

    /** body.unit_ids(단원 여러 개) 또는 body.label(직접 입력) 로 시험 범위를 추가한다. */
    public static function addScopes(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $es = Owned::find('exam_subjects', $p['id'], $uid);
        $b = $req->body;
        $next = (int) Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM exam_scopes WHERE exam_subject_id = ?', [$es['id']])['n'];

        Db::transaction(function () use ($uid, $es, $b, &$next): void {
            if (isset($b['unit_ids'])) {
                $ids = $b['unit_ids'];
                if (!is_array($ids) || count($ids) === 0 || count($ids) > 60) {
                    throw new HttpException(422, 'validation', '단원을 1~60개 골라 주세요.', 'unit_ids');
                }
                foreach (array_values(array_unique(array_map('intval', $ids))) as $unitId) {
                    $unit = Db::one(
                        'SELECT u.id, u.title, u.page_start, u.page_end, t.subject_id
                           FROM units u JOIN textbooks t ON t.id = u.textbook_id
                          WHERE u.id = ? AND u.user_id = ?',
                        [$unitId, $uid]
                    );
                    if ($unit === null || (int) $unit['subject_id'] !== (int) $es['subject_id']) {
                        throw new HttpException(422, 'validation', '이 과목의 단원만 시험 범위로 추가할 수 있어요.', 'unit_ids');
                    }
                    $dup = Db::one('SELECT id FROM exam_scopes WHERE exam_subject_id = ? AND unit_id = ?', [$es['id'], $unitId]);
                    if ($dup !== null) {
                        continue;
                    }
                    Db::run(
                        'INSERT INTO exam_scopes (user_id, exam_subject_id, unit_id, label, page_start, page_end, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$uid, $es['id'], $unitId, $unit['title'], $unit['page_start'], $unit['page_end'], $next++]
                    );
                }
                return;
            }

            $label = (string) Input::str($b, 'label', 1, 120, '범위');
            [$start, $end] = self::pages($b);
            Db::run(
                'INSERT INTO exam_scopes (user_id, exam_subject_id, label, page_start, page_end, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
                [$uid, $es['id'], $label, $start, $end, $next]
            );
        });
        return ExamController::detail($uid, (int) $es['exam_id']);
    }

    public static function updateScope(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('exam_scopes', $p['id'], $uid);
        $b = $req->body;
        $label = Input::str($b, 'label', 1, 120, '범위', false) ?? $row['label'];
        [$start, $end] = array_key_exists('page_start', $b) || array_key_exists('page_end', $b)
            ? self::pages($b) : [$row['page_start'], $row['page_end']];
        $progress = Input::int($b, 'progress', 0, 100, '진도', false) ?? (int) $row['progress'];
        $order = Input::int($b, 'sort_order', 0, 100000, '순서', false) ?? (int) $row['sort_order'];

        Db::run(
            'UPDATE exam_scopes SET label = ?, page_start = ?, page_end = ?, progress = ?, sort_order = ? WHERE id = ? AND user_id = ?',
            [$label, $start, $end, $progress, $order, $row['id'], $uid]
        );
        $es = Owned::find('exam_subjects', (int) $row['exam_subject_id'], $uid);
        return ExamController::detail($uid, (int) $es['exam_id']);
    }

    public static function deleteScope(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('exam_scopes', $p['id'], $uid);
        $es = Owned::find('exam_subjects', (int) $row['exam_subject_id'], $uid);
        Db::run('DELETE FROM exam_scopes WHERE id = ? AND user_id = ?', [$row['id'], $uid]);
        return ExamController::detail($uid, (int) $es['exam_id']);
    }

    /** 시험 기간 안의 날짜여야 한다. */
    private static function examDate(array $b, array $exam, string $default): string
    {
        $date = Input::date($b, 'exam_date', '시험일', $default);
        if ($date < $exam['start_date'] || $date > $exam['end_date']) {
            throw new HttpException(422, 'validation', "시험일은 시험 기간({$exam['start_date']} ~ {$exam['end_date']}) 안이어야 해요.", 'exam_date');
        }
        return $date;
    }

    /** @return array{0:?int,1:?int} [점수, 등급] */
    private static function scoreGrade(array $b, string $prefix): array
    {
        return [
            Input::int($b, "{$prefix}_score", 0, 100, '점수', false),
            Input::int($b, "{$prefix}_grade", 1, 9, '등급', false),
        ];
    }

    /** @return array{0:?int,1:?int} */
    private static function pages(array $b): array
    {
        $start = Input::int($b, 'page_start', 1, 5000, '시작 쪽', false);
        $end = Input::int($b, 'page_end', 1, 5000, '끝 쪽', false);
        if ($start !== null && $end !== null && $end < $start) {
            throw new HttpException(422, 'validation', '끝 쪽은 시작 쪽보다 크거나 같아야 해요.', 'page_end');
        }
        return [$start, $end];
    }
}
