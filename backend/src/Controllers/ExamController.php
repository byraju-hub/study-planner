<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

/** 시험(중간·기말·모의고사). 과목별 시험일과 범위는 ExamSubjectController, 대비 계획은 ExamPlanController. */
final class ExamController
{
    private const TYPES = ['midterm', 'final', 'mock'];

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $params = [$uid];
        $where = '';
        if (isset($req->query['type']) && $req->query['type'] !== '') {
            $where = 'AND e.type = ?';
            $params[] = Input::enum($req->query, 'type', self::TYPES, '시험 종류');
        }
        $rows = Db::all(
            "SELECT e.id, e.type, e.title, e.start_date, e.end_date, e.memo,
                    (SELECT COUNT(*) FROM exam_subjects es WHERE es.exam_id = e.id) AS subject_count,
                    (SELECT AVG(sc.progress) FROM exam_scopes sc JOIN exam_subjects es ON es.id = sc.exam_subject_id
                      WHERE es.exam_id = e.id) AS progress
               FROM exams e WHERE e.user_id = ? $where",
            $params
        );
        $today = date('Y-m-d');
        $rows = array_map(static function (array $r) use ($today): array {
            $r['d_day'] = self::daysUntil($r['start_date'], $today);
            $r['status'] = $today < $r['start_date'] ? 'upcoming' : ($today <= $r['end_date'] ? 'ongoing' : 'done');
            $r['subject_count'] = (int) $r['subject_count'];
            $r['progress'] = $r['progress'] === null ? null : (int) round((float) $r['progress']);
            return $r;
        }, $rows);

        // 다가오는/진행 중인 시험은 가까운 순, 지난 시험은 최근 순
        usort($rows, static function (array $a, array $b): int {
            $aDone = $a['status'] === 'done';
            $bDone = $b['status'] === 'done';
            if ($aDone !== $bDone) {
                return $aDone <=> $bDone;
            }
            return $aDone ? $b['start_date'] <=> $a['start_date'] : $a['start_date'] <=> $b['start_date'];
        });
        return $rows;
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        [$type, $title, $start, $end, $memo] = self::fields($req->body, null);
        $id = Db::insert(
            'INSERT INTO exams (user_id, type, title, start_date, end_date, memo) VALUES (?, ?, ?, ?, ?, ?)',
            [$uid, $type, $title, $start, $end, $memo]
        );
        return self::detail($uid, $id);
    }

    public static function get(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('exams', $p['id'], $uid);
        return self::detail($uid, $p['id']);
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('exams', $p['id'], $uid);
        [$type, $title, $start, $end, $memo] = self::fields($req->body, $row);
        Db::run(
            'UPDATE exams SET type = ?, title = ?, start_date = ?, end_date = ?, memo = ? WHERE id = ? AND user_id = ?',
            [$type, $title, $start, $end, $memo, $row['id'], $uid]
        );
        return self::detail($uid, (int) $row['id']);
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('exams', $p['id'], $uid);
        Db::transaction(static function () use ($uid, $p): void {
            Db::run(
                "DELETE FROM tasks WHERE user_id = ? AND source = 'exam'
                   AND ref_id IN (SELECT id FROM exam_subjects WHERE exam_id = ? AND user_id = ?)",
                [$uid, $p['id'], $uid]
            );
            Db::run('DELETE FROM exams WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        });
        return ['deleted' => true];
    }

    /** 입력한 성적(점수·등급)이 있는 과목들. 모의고사 성적 추이 확인용. */
    public static function results(Request $req): array
    {
        $uid = Session::requireUser();
        $params = [$uid];
        $where = '';
        if (isset($req->query['type']) && $req->query['type'] !== '') {
            $where = 'AND e.type = ?';
            $params[] = Input::enum($req->query, 'type', self::TYPES, '시험 종류');
        }
        return Db::all(
            "SELECT e.id AS exam_id, e.title AS exam_title, e.type, es.exam_date,
                    s.name AS subject_name, s.color AS subject_color,
                    es.target_score, es.target_grade, es.actual_score, es.actual_grade
               FROM exam_subjects es
               JOIN exams e ON e.id = es.exam_id
               JOIN subjects s ON s.id = es.subject_id
              WHERE es.user_id = ? AND (es.actual_score IS NOT NULL OR es.actual_grade IS NOT NULL) $where
              ORDER BY es.exam_date, s.sort_order, es.id",
            $params
        );
    }

    /** @return array<string,mixed> */
    public static function detail(int $uid, int $id): array
    {
        $exam = Db::one(
            'SELECT id, type, title, start_date, end_date, memo FROM exams WHERE id = ? AND user_id = ?',
            [$id, $uid]
        );
        if ($exam === null) {
            throw new HttpException(404, 'not_found', '찾을 수 없는 시험입니다.');
        }
        $today = date('Y-m-d');
        $exam['d_day'] = self::daysUntil($exam['start_date'], $today);
        $exam['status'] = $today < $exam['start_date'] ? 'upcoming' : ($today <= $exam['end_date'] ? 'ongoing' : 'done');

        $subjects = Db::all(
            'SELECT es.id, es.subject_id, s.name AS subject_name, s.color AS subject_color, es.exam_date, es.period,
                    es.target_score, es.target_grade, es.actual_score, es.actual_grade
               FROM exam_subjects es JOIN subjects s ON s.id = es.subject_id
              WHERE es.exam_id = ? AND es.user_id = ?
              ORDER BY es.exam_date, es.period, es.id',
            [$id, $uid]
        );
        $ids = array_map(static fn(array $s): int => (int) $s['id'], $subjects);

        $scopes = [];
        $tasks = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all(
                "SELECT id, exam_subject_id, unit_id, label, page_start, page_end, progress, sort_order
                   FROM exam_scopes WHERE user_id = ? AND exam_subject_id IN ($in) ORDER BY sort_order, id",
                [$uid, ...$ids]
            ) as $sc) {
                $scopes[(int) $sc['exam_subject_id']][] = $sc;
            }
            foreach (Db::all(
                "SELECT ref_id, COUNT(*) AS total, SUM(done_at IS NOT NULL) AS done FROM tasks
                  WHERE user_id = ? AND source = 'exam' AND ref_id IN ($in) GROUP BY ref_id",
                [$uid, ...$ids]
            ) as $t) {
                $tasks[(int) $t['ref_id']] = [(int) $t['total'], (int) $t['done']];
            }
        }

        foreach ($subjects as &$s) {
            $sid = (int) $s['id'];
            $s['scopes'] = $scopes[$sid] ?? [];
            $s['d_day'] = self::daysUntil($s['exam_date'], $today);
            $s['progress'] = $s['scopes'] ? (int) round(array_sum(array_column($s['scopes'], 'progress')) / count($s['scopes'])) : null;
            [$s['task_total'], $s['task_done']] = $tasks[$sid] ?? [0, 0];
        }
        unset($s);
        $exam['subjects'] = $subjects;
        return $exam;
    }

    /** 시험일까지 남은 일수. 오늘이면 0, 지났으면 음수. */
    public static function daysUntil(string $date, string $today): int
    {
        return (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($date))->format('%r%a');
    }

    /**
     * @param array<string,mixed> $b
     * @param array<string,mixed>|null $current
     * @return array{0:string,1:string,2:string,3:string,4:?string}
     */
    private static function fields(array $b, ?array $current): array
    {
        $type = Input::enum($b, 'type', self::TYPES, '시험 종류', $current['type'] ?? null);
        $title = Input::str($b, 'title', 1, 80, '시험 이름', $current === null) ?? $current['title'];
        $start = Input::date($b, 'start_date', '시작일', $current['start_date'] ?? null);
        $end = Input::date($b, 'end_date', '종료일', $current['end_date'] ?? ($type === 'mock' ? $start : null));
        if ($end < $start) {
            throw new HttpException(422, 'validation', '종료일은 시작일보다 같거나 뒤여야 해요.', 'end_date');
        }
        $memo = array_key_exists('memo', $b) ? Input::text($b, 'memo', 2000, '메모') : ($current['memo'] ?? null);
        return [$type, $title, $start, $end, $memo];
    }
}
