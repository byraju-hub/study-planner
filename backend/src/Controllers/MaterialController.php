<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Files;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

/** 학습 자료는 본인만 볼 수 있다. 공유 기능은 두지 않는다. */
final class MaterialController
{
    private const FILE_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    private const COLUMNS = 'id, unit_id, type, title, content, url, file_name, file_size, mime, created_at';

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $unitId = (int) Input::int($req->query, 'unit_id', 1, PHP_INT_MAX, '단원');
        Owned::find('sp_units', $unitId, $uid);
        return Db::all(
            'SELECT ' . self::COLUMNS . ' FROM sp_materials WHERE user_id = ? AND unit_id = ? ORDER BY id DESC',
            [$uid, $unitId]
        );
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $unitId = (int) Input::int($b, 'unit_id', 1, PHP_INT_MAX, '단원');
        Owned::find('sp_units', $unitId, $uid);
        $type = Input::enum($b, 'type', ['note', 'link'], '자료 종류');
        $title = (string) Input::str($b, 'title', 1, 120, '제목');

        $content = null;
        $url = null;
        if ($type === 'note') {
            $content = Input::text($b, 'content', 20000, '메모');
            if ($content === null || trim($content) === '') {
                throw new HttpException(422, 'validation', '메모 내용을 입력해 주세요.', 'content');
            }
        } else {
            $url = self::url($b);
        }

        $id = Db::insert(
            'INSERT INTO sp_materials (user_id, unit_id, type, title, content, url) VALUES (?, ?, ?, ?, ?, ?)',
            [$uid, $unitId, $type, $title, $content, $url]
        );
        return self::fetch($id, $uid);
    }

    public static function upload(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        // 본문이 post_max_size를 넘으면 PHP가 폼 값과 파일을 모두 비운다.
        if ($b === [] && $req->files === []) {
            throw new HttpException(413, 'too_large', '파일이 너무 커요. 10MB 이하만 올릴 수 있어요.', 'file');
        }
        $unitId = (int) Input::int($b, 'unit_id', 1, PHP_INT_MAX, '단원');
        Owned::find('sp_units', $unitId, $uid);

        $f = $req->files['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new HttpException(422, 'validation', '파일을 선택해 주세요. 파일이 너무 크면 올라가지 않아요.', 'file');
        }
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpException(413, 'too_large', '파일이 너무 커요. 10MB 이하만 올릴 수 있어요.', 'file');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new HttpException(422, 'validation', '파일을 올리지 못했어요. 다시 시도해 주세요.', 'file');
        }
        if ($f['size'] > Files::maxBytes()) {
            throw new HttpException(413, 'too_large', '파일이 너무 커요. 10MB 이하만 올릴 수 있어요.', 'file');
        }

        $original = self::cleanName((string) $f['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::FILE_TYPES[$ext])) {
            throw new HttpException(422, 'validation', 'PDF, JPG, PNG 파일만 올릴 수 있어요.', 'file');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($mime !== self::FILE_TYPES[$ext]) {
            throw new HttpException(422, 'validation', '파일 내용이 확장자와 맞지 않아요.', 'file');
        }

        $title = Input::str($b, 'title', 1, 120, '제목', false) ?? mb_substr(pathinfo($original, PATHINFO_FILENAME), 0, 120);
        $rel = $uid . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = Files::abs($rel);
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new \RuntimeException('업로드 폴더를 만들 수 없습니다.');
        }
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new \RuntimeException('업로드 파일을 저장하지 못했습니다.');
        }
        chmod($dest, 0640);

        try {
            $id = Db::insert(
                'INSERT INTO sp_materials (user_id, unit_id, type, title, file_path, file_name, file_size, mime)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, $unitId, 'file', $title, $rel, $original, (int) $f['size'], $mime]
            );
        } catch (\Throwable $e) {
            Files::remove([$rel]);
            throw $e;
        }
        return self::fetch($id, $uid);
    }

    public static function file(Request $req, array $p): string
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_materials', $p['id'], $uid);
        if ($row['type'] !== 'file' || $row['file_path'] === null) {
            throw new HttpException(404, 'not_found', '파일이 없는 자료입니다.');
        }
        $path = Files::abs((string) $row['file_path']);
        if (!is_file($path)) {
            throw new HttpException(404, 'not_found', '파일을 찾을 수 없습니다.');
        }
        header('Content-Type: ' . $row['mime']);
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode((string) $row['file_name']));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        return Router::STREAMED;
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_materials', $p['id'], $uid);
        $b = $req->body;
        $title = Input::str($b, 'title', 1, 120, '제목', false) ?? $row['title'];
        $content = $row['content'];
        $url = $row['url'];
        if ($row['type'] === 'note' && array_key_exists('content', $b)) {
            $content = Input::text($b, 'content', 20000, '메모');
            if ($content === null || trim($content) === '') {
                throw new HttpException(422, 'validation', '메모 내용을 입력해 주세요.', 'content');
            }
        }
        if ($row['type'] === 'link' && array_key_exists('url', $b)) {
            $url = self::url($b);
        }
        Db::run(
            'UPDATE sp_materials SET title = ?, content = ?, url = ? WHERE id = ? AND user_id = ?',
            [$title, $content, $url, $row['id'], $uid]
        );
        return self::fetch((int) $row['id'], $uid);
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_materials', $p['id'], $uid);
        Db::run('DELETE FROM sp_materials WHERE id = ? AND user_id = ?', [$row['id'], $uid]);
        if ($row['file_path'] !== null) {
            Files::remove([(string) $row['file_path']]);
        }
        return ['deleted' => true];
    }

    private static function url(array $b): string
    {
        $url = (string) Input::str($b, 'url', 1, 500, '링크 주소');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new HttpException(422, 'validation', 'http:// 또는 https:// 로 시작하는 주소를 입력해 주세요.', 'url');
        }
        return $url;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        return mb_substr($name, 0, 255);
    }

    /** @return array<string,mixed> */
    private static function fetch(int $id, int $uid): array
    {
        return Db::one('SELECT ' . self::COLUMNS . ' FROM sp_materials WHERE id = ? AND user_id = ?', [$id, $uid]) ?? [];
    }
}
