<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Files;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

/** 단원은 2단계: 대단원(parent_id 없음) > 중단원. */
final class UnitController
{
    /** 평평한 목록을 돌려준다. 트리 구성은 화면에서 한다. */
    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $textbookId = (int) Input::int($req->query, 'textbook_id', 1, PHP_INT_MAX, '교재');
        Owned::find('textbooks', $textbookId, $uid);
        return Db::all(
            'SELECT u.id, u.textbook_id, u.parent_id, u.title, u.page_start, u.page_end, u.sort_order,
                    (SELECT COUNT(*) FROM materials m WHERE m.unit_id = u.id) AS material_count
               FROM units u
              WHERE u.user_id = ? AND u.textbook_id = ?
              ORDER BY u.sort_order, u.id',
            [$uid, $textbookId]
        );
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $textbookId = (int) Input::int($b, 'textbook_id', 1, PHP_INT_MAX, '교재');
        Owned::find('textbooks', $textbookId, $uid);
        $parentId = Input::int($b, 'parent_id', 1, PHP_INT_MAX, '대단원', false);
        if ($parentId !== null) {
            $parent = Owned::find('units', $parentId, $uid);
            if ((int) $parent['textbook_id'] !== $textbookId || $parent['parent_id'] !== null) {
                throw new HttpException(422, 'validation', '대단원을 다시 선택해 주세요.', 'parent_id');
            }
        }
        $title = (string) Input::str($b, 'title', 1, 120, '단원 이름');
        [$start, $end] = self::pages($b);

        $next = (int) Db::one(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM units WHERE textbook_id = ? AND parent_id <=> ?',
            [$textbookId, $parentId]
        )['n'];
        $id = Db::insert(
            'INSERT INTO units (user_id, textbook_id, parent_id, title, page_start, page_end, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uid, $textbookId, $parentId, $title, $start, $end, $next]
        );
        return [
            'id' => $id, 'textbook_id' => $textbookId, 'parent_id' => $parentId, 'title' => $title,
            'page_start' => $start, 'page_end' => $end, 'sort_order' => $next, 'material_count' => 0,
        ];
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('units', $p['id'], $uid);
        $b = $req->body;
        $title = Input::str($b, 'title', 1, 120, '단원 이름', false) ?? $row['title'];
        if (array_key_exists('page_start', $b) || array_key_exists('page_end', $b)) {
            [$start, $end] = self::pages($b);
        } else {
            [$start, $end] = [$row['page_start'], $row['page_end']];
        }
        $order = Input::int($b, 'sort_order', 0, 100000, '순서', false) ?? (int) $row['sort_order'];

        Db::run(
            'UPDATE units SET title = ?, page_start = ?, page_end = ?, sort_order = ? WHERE id = ? AND user_id = ?',
            [$title, $start, $end, $order, $row['id'], $uid]
        );
        return [
            'id' => (int) $row['id'], 'title' => $title, 'page_start' => $start, 'page_end' => $end, 'sort_order' => $order,
        ];
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('units', $p['id'], $uid);
        $files = Files::pathsUnderUnit($p['id'], $uid);
        Db::run('DELETE FROM units WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        Files::remove($files);
        return ['deleted' => true];
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
