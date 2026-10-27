<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

final class SubjectController
{
    private const DEFAULTS = [
        ['공통국어', '#9A3412'],
        ['공통수학', '#1E4E8C'],
        ['공통영어', '#9D2459'],
        ['통합사회', '#8A5A00'],
        ['통합과학', '#5B34A0'],
        ['한국사', '#5B6475'],
        ['과학탐구실험', '#0F766E'],
        ['정보', '#475569'],
    ];

    public static function seedDefaults(int $userId): void
    {
        foreach (self::DEFAULTS as $i => [$name, $color]) {
            Db::run(
                'INSERT INTO subjects (user_id, name, color, sort_order) VALUES (?, ?, ?, ?)',
                [$userId, $name, $color, $i + 1]
            );
        }
    }

    public static function list(Request $req): array
    {
        return Db::all(
            'SELECT s.id, s.name, s.color, s.sort_order,
                    (SELECT COUNT(*) FROM textbooks t WHERE t.subject_id = s.id) AS textbook_count
               FROM subjects s
              WHERE s.user_id = ? AND s.is_active = 1
              ORDER BY s.sort_order, s.id',
            [Session::requireUser()]
        );
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $name = Input::str($req->body, 'name', 1, 40, '과목 이름');
        $color = Input::color($req->body, 'color', '#5B6475');
        $next = (int) (Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM subjects WHERE user_id = ?', [$uid])['n'] ?? 1);

        try {
            $id = Db::insert(
                'INSERT INTO subjects (user_id, name, color, sort_order) VALUES (?, ?, ?, ?)',
                [$uid, $name, $color, $next]
            );
        } catch (\PDOException $e) {
            throw Owned::isDuplicate($e)
                ? new HttpException(409, 'duplicate', '이미 있는 과목 이름입니다.', 'name')
                : $e;
        }
        return ['id' => $id, 'name' => $name, 'color' => $color, 'sort_order' => $next, 'textbook_count' => 0];
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('subjects', $p['id'], $uid);
        $name = Input::str($req->body, 'name', 1, 40, '과목 이름', false) ?? $row['name'];
        $color = Input::color($req->body, 'color', $row['color']);

        try {
            Db::run('UPDATE subjects SET name = ?, color = ? WHERE id = ? AND user_id = ?', [$name, $color, $row['id'], $uid]);
        } catch (\PDOException $e) {
            throw Owned::isDuplicate($e)
                ? new HttpException(409, 'duplicate', '이미 있는 과목 이름입니다.', 'name')
                : $e;
        }
        return ['id' => (int) $row['id'], 'name' => $name, 'color' => $color];
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('subjects', $p['id'], $uid);
        Db::run('DELETE FROM subjects WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        return ['deleted' => true];
    }
}
