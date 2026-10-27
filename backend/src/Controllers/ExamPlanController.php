<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;
use App\Services\PlanService;

/** 시험 대비 계획: 미리 계산해 보여 주고(preview), 학생이 고친 내용을 확정하면(apply) 할 일로 만든다. */
final class ExamPlanController
{
    private const MAX_ITEMS = 500;

    public static function preview(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('exams', $p['id'], $uid);
        $today = date('Y-m-d');

        $subjects = [];
        foreach (ExamController::detail($uid, $p['id'])['subjects'] as $s) {
            $subjects[] = [
                'id' => (int) $s['id'],
                'subject_id' => (int) $s['subject_id'],
                'subject_name' => $s['subject_name'],
                'exam_date' => $s['exam_date'],
                'scopes' => array_map(static fn(array $sc): array => [
                    'label' => $sc['label'],
                    'pages' => $sc['page_start'] !== null && $sc['page_end'] !== null ? (int) $sc['page_end'] - (int) $sc['page_start'] + 1 : null,
                ], array_values(array_filter($s['scopes'], static fn(array $sc): bool => (int) $sc['progress'] < 100))),
            ];
        }
        if (count($subjects) === 0) {
            throw new HttpException(422, 'validation', '시험 과목을 먼저 추가해 주세요.');
        }

        $plan = PlanService::generate($subjects, $today);
        return ['today' => $today] + $plan;
    }

    /** body.items 로 이 시험의 계획을 통째로 바꾼다. 이미 완료한 할 일은 그대로 남긴다. */
    public static function apply(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $exam = Owned::find('exams', $p['id'], $uid);
        $items = $req->body['items'] ?? null;
        if (!is_array($items) || count($items) > self::MAX_ITEMS) {
            throw new HttpException(422, 'validation', '계획 형식이 올바르지 않거나 너무 많아요. (최대 ' . self::MAX_ITEMS . '개)', 'items');
        }

        $subjects = [];
        foreach (Db::all('SELECT id, subject_id, exam_date FROM exam_subjects WHERE exam_id = ? AND user_id = ?', [$exam['id'], $uid]) as $s) {
            $subjects[(int) $s['id']] = $s;
        }
        $today = date('Y-m-d');
        $rows = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                throw new HttpException(422, 'validation', '계획 형식이 올바르지 않습니다.', 'items');
            }
            $esId = (int) Input::int($it, 'exam_subject_id', 1, PHP_INT_MAX, '과목');
            if (!isset($subjects[$esId])) {
                throw new HttpException(422, 'validation', '이 시험에 없는 과목이 들어 있어요.', 'items');
            }
            $date = Input::date($it, 'task_date', '날짜');
            if ($date < $today || $date >= $subjects[$esId]['exam_date']) {
                throw new HttpException(422, 'validation', '계획 날짜는 오늘부터 시험 전날까지여야 해요.', 'items');
            }
            $rows[] = [
                $uid, $subjects[$esId]['subject_id'], $esId,
                (string) Input::str($it, 'title', 1, 120, '할 일'),
                $date,
                Input::int($it, 'est_minutes', 1, 600, '예상 시간', false),
            ];
        }

        $kept = 0;
        Db::transaction(static function () use ($uid, $exam, $rows, &$kept): void {
            $scope = "user_id = ? AND source = 'exam' AND ref_id IN (SELECT id FROM exam_subjects WHERE exam_id = ? AND user_id = ?)";
            $kept = (int) Db::one("SELECT COUNT(*) AS n FROM tasks WHERE $scope AND done_at IS NOT NULL", [$uid, $exam['id'], $uid])['n'];
            Db::run("DELETE FROM tasks WHERE $scope AND done_at IS NULL", [$uid, $exam['id'], $uid]);
            foreach ($rows as $i => [$u, $subjectId, $esId, $title, $date, $minutes]) {
                Db::run(
                    "INSERT INTO tasks (user_id, subject_id, source, ref_id, title, task_date, est_minutes, sort_order)
                     VALUES (?, ?, 'exam', ?, ?, ?, ?, ?)",
                    [$u, $subjectId, $esId, $title, $date, $minutes, 100 + $i]
                );
            }
        });
        return ['created' => count($rows), 'kept_done' => $kept];
    }

    /** 이 시험 계획에서 만들어진 할 일 전체(날짜순). */
    public static function tasks(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('exams', $p['id'], $uid);
        return TaskController::fetch(
            $uid,
            "t.source = 'exam' AND t.ref_id IN (SELECT id FROM exam_subjects WHERE exam_id = ? AND user_id = ?)",
            [$p['id'], $uid]
        );
    }
}
