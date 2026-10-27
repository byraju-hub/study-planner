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

final class TextbookController
{
    private const KINDS = ['textbook', 'guide', 'workbook', 'academy', 'etc'];

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $subjectId = (int) Input::int($req->query, 'subject_id', 1, PHP_INT_MAX, '과목');
        Owned::find('sp_subjects', $subjectId, $uid);
        return Db::all(
            'SELECT t.id, t.subject_id, t.title, t.kind, t.publisher, t.total_pages,
                    (SELECT COUNT(*) FROM sp_units u WHERE u.textbook_id = t.id) AS unit_count
               FROM sp_textbooks t
              WHERE t.user_id = ? AND t.subject_id = ?
              ORDER BY t.id',
            [$uid, $subjectId]
        );
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $subjectId = (int) Input::int($b, 'subject_id', 1, PHP_INT_MAX, '과목');
        Owned::find('sp_subjects', $subjectId, $uid);
        $title = (string) Input::str($b, 'title', 1, 120, '교재 이름');
        $kind = Input::enum($b, 'kind', self::KINDS, '교재 종류', 'textbook');
        $publisher = Input::str($b, 'publisher', 1, 60, '출판사', false);
        $pages = Input::int($b, 'total_pages', 1, 5000, '전체 쪽수', false);

        $id = Db::insert(
            'INSERT INTO sp_textbooks (user_id, subject_id, title, kind, publisher, total_pages) VALUES (?, ?, ?, ?, ?, ?)',
            [$uid, $subjectId, $title, $kind, $publisher, $pages]
        );
        return [
            'id' => $id, 'subject_id' => $subjectId, 'title' => $title, 'kind' => $kind,
            'publisher' => $publisher, 'total_pages' => $pages, 'unit_count' => 0,
        ];
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_textbooks', $p['id'], $uid);
        $b = $req->body;
        $title = Input::str($b, 'title', 1, 120, '교재 이름', false) ?? $row['title'];
        $kind = Input::enum($b, 'kind', self::KINDS, '교재 종류', $row['kind']);
        $publisher = array_key_exists('publisher', $b) ? Input::str($b, 'publisher', 1, 60, '출판사', false) : $row['publisher'];
        $pages = array_key_exists('total_pages', $b) ? Input::int($b, 'total_pages', 1, 5000, '전체 쪽수', false) : $row['total_pages'];

        Db::run(
            'UPDATE sp_textbooks SET title = ?, kind = ?, publisher = ?, total_pages = ? WHERE id = ? AND user_id = ?',
            [$title, $kind, $publisher, $pages, $row['id'], $uid]
        );
        return [
            'id' => (int) $row['id'], 'subject_id' => (int) $row['subject_id'], 'title' => $title, 'kind' => $kind,
            'publisher' => $publisher, 'total_pages' => $pages,
        ];
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('sp_textbooks', $p['id'], $uid);
        $files = Files::pathsUnderTextbook($p['id'], $uid);
        Db::run('DELETE FROM sp_textbooks WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        Files::remove($files);
        return ['deleted' => true];
    }
}
