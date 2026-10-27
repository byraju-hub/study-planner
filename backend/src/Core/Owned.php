<?php
declare(strict_types=1);

namespace App\Core;

/** 로그인한 사용자 소유의 행만 꺼낸다. 남의 데이터나 없는 데이터는 모두 404. */
final class Owned
{
    private const TABLES = ['subjects', 'textbooks', 'units', 'materials', 'tasks', 'review_items', 'study_sessions', 'exams', 'exam_subjects', 'exam_scopes'];

    /** @return array<string,mixed> */
    public static function find(string $table, int $id, int $userId): array
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException("허용되지 않은 테이블: $table");
        }
        $row = Db::one("SELECT * FROM `$table` WHERE id = ? AND user_id = ?", [$id, $userId]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', '찾을 수 없는 항목입니다.');
        }
        return $row;
    }

    public static function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? 0) === 1062;
    }
}
