<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

/** 할 일. 일반 스터디·시험 대비·수행평가가 모두 이 테이블을 쓰고 source로 구분한다. */
final class TaskController
{
    private const MAX_RANGE_DAYS = 62;

    private const SELECT = 'SELECT t.id, t.subject_id, s.name AS subject_name, s.color AS subject_color,
                                   t.source, t.ref_id, t.title, t.task_date, t.est_minutes, t.done_at
                              FROM tasks t LEFT JOIN subjects s ON s.id = t.subject_id';

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $from = Input::date($req->query, 'from', '시작일', date('Y-m-d'));
        $to = Input::date($req->query, 'to', '종료일', $from);
        if ($to < $from || (strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
            throw new HttpException(422, 'validation', '조회 기간은 62일 이내여야 해요.', 'to');
        }
        return self::fetch($uid, 't.task_date BETWEEN ? AND ?', [$from, $to]);
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $title = (string) Input::str($b, 'title', 1, 120, '할 일');
        $date = Input::date($b, 'task_date', '날짜', date('Y-m-d'));
        $subjectId = Input::ownedSubject($b, 'subject_id', $uid);
        $minutes = Input::int($b, 'est_minutes', 1, 600, '예상 시간', false);
        $order = (int) Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM tasks WHERE user_id = ? AND task_date = ?', [$uid, $date])['n'];

        $id = Db::insert(
            'INSERT INTO tasks (user_id, subject_id, source, title, task_date, est_minutes, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uid, $subjectId, 'study', $title, $date, $minutes, $order]
        );
        return self::one($uid, $id);
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('tasks', $p['id'], $uid);
        $b = $req->body;
        $title = Input::str($b, 'title', 1, 120, '할 일', false) ?? $row['title'];
        $date = Input::date($b, 'task_date', '날짜', $row['task_date']);
        $subjectId = array_key_exists('subject_id', $b) ? Input::ownedSubject($b, 'subject_id', $uid) : $row['subject_id'];
        $minutes = array_key_exists('est_minutes', $b) ? Input::int($b, 'est_minutes', 1, 600, '예상 시간', false) : $row['est_minutes'];

        Db::run(
            'UPDATE tasks SET title = ?, task_date = ?, subject_id = ?, est_minutes = ? WHERE id = ? AND user_id = ?',
            [$title, $date, $subjectId, $minutes, $row['id'], $uid]
        );
        return self::one($uid, (int) $row['id']);
    }

    /** body.done 이 있으면 그 값으로, 없으면 현재 상태를 뒤집는다. */
    public static function toggle(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('tasks', $p['id'], $uid);
        $done = array_key_exists('done', $req->body) ? (bool) $req->body['done'] : $row['done_at'] === null;
        Db::run('UPDATE tasks SET done_at = ' . ($done ? 'NOW()' : 'NULL') . ' WHERE id = ? AND user_id = ?', [$row['id'], $uid]);
        return self::one($uid, (int) $row['id']);
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('tasks', $p['id'], $uid);
        Db::run('DELETE FROM tasks WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        return ['deleted' => true];
    }

    /** @return list<array<string,mixed>> */
    public static function fetch(int $uid, string $where, array $params): array
    {
        $rows = Db::all(
            self::SELECT . " WHERE t.user_id = ? AND $where ORDER BY t.task_date, (t.done_at IS NOT NULL), t.sort_order, t.id",
            [$uid, ...$params]
        );
        return array_map([self::class, 'format'], $rows);
    }

    /** @return array<string,mixed> */
    public static function one(int $uid, int $id): array
    {
        return self::fetch($uid, 't.id = ?', [$id])[0] ?? [];
    }

    /** @return array<string,mixed> */
    private static function format(array $r): array
    {
        $r['done'] = $r['done_at'] !== null;
        return $r;
    }
}
