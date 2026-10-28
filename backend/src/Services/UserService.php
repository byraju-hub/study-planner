<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Files;

final class UserService
{
    /** 회원과 그 사람의 모든 데이터(DB는 외래키로 함께 삭제)와 업로드 파일을 지운다. */
    public static function delete(int $userId): void
    {
        $files = array_map(
            static fn(array $r): string => (string) $r['file_path'],
            Db::all('SELECT file_path FROM sp_materials WHERE user_id = ? AND file_path IS NOT NULL', [$userId])
        );
        Db::run('DELETE FROM sp_users WHERE id = ?', [$userId]);
        Files::remove($files);
        Files::removeUserDir($userId);
    }

    /** 헷갈리는 글자(0 O 1 l I)를 뺀 임시 비밀번호. 영문자와 숫자가 모두 들어간다. */
    public static function temporaryPassword(int $length = 10): string
    {
        $letters = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $all = $letters . $digits;
        $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
