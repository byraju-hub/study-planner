<?php
declare(strict_types=1);

namespace App\Core;

/** 업로드 파일 저장 위치와 삭제. DB에는 upload_dir 기준 상대 경로만 저장한다. */
final class Files
{
    public static function dir(): string
    {
        return rtrim((string) $GLOBALS['sp_config']['upload_dir'], '/');
    }

    public static function maxBytes(): int
    {
        return (int) ($GLOBALS['sp_config']['upload_max_bytes'] ?? 10 * 1024 * 1024);
    }

    public static function abs(string $rel): string
    {
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            throw new \InvalidArgumentException('잘못된 파일 경로');
        }
        return self::dir() . '/' . $rel;
    }

    /** @param list<string> $relPaths */
    public static function remove(array $relPaths): void
    {
        foreach ($relPaths as $rel) {
            $path = self::abs($rel);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** 사용자 업로드 폴더가 비어 있으면 지운다. */
    public static function removeUserDir(int $userId): void
    {
        $dir = self::dir() . '/' . $userId;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $left) {
                @unlink($left);
            }
            @rmdir($dir);
        }
    }

    /** @return list<string> */
    public static function pathsUnderUnit(int $unitId, int $userId): array
    {
        return self::column(
            'SELECT m.file_path FROM sp_materials m
              WHERE m.user_id = ? AND m.file_path IS NOT NULL
                AND (m.unit_id = ? OR m.unit_id IN (SELECT id FROM sp_units WHERE parent_id = ?))',
            [$userId, $unitId, $unitId]
        );
    }

    /** @return list<string> */
    public static function pathsUnderTextbook(int $textbookId, int $userId): array
    {
        return self::column(
            'SELECT m.file_path FROM sp_materials m JOIN sp_units u ON u.id = m.unit_id
              WHERE m.user_id = ? AND m.file_path IS NOT NULL AND u.textbook_id = ?',
            [$userId, $textbookId]
        );
    }

    /** @return list<string> */
    public static function pathsUnderSubject(int $subjectId, int $userId): array
    {
        return self::column(
            'SELECT m.file_path FROM sp_materials m
               JOIN sp_units u ON u.id = m.unit_id
               JOIN sp_textbooks t ON t.id = u.textbook_id
              WHERE m.user_id = ? AND m.file_path IS NOT NULL AND t.subject_id = ?',
            [$userId, $subjectId]
        );
    }

    /** @return list<string> */
    private static function column(string $sql, array $params): array
    {
        return array_map(static fn(array $r): string => (string) $r['file_path'], Db::all($sql, $params));
    }
}
