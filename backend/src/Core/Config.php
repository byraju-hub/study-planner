<?php
declare(strict_types=1);

namespace App\Core;

/** config.php 값 읽기. 점(.)으로 중첩 키를 읽는다: Config::get('ai.model', 'x') */
final class Config
{
    public static function get(string $path, mixed $default = null): mixed
    {
        $cur = $GLOBALS['sp_config'] ?? [];
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return $default;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    /** 최고 관리자의 아이디(소문자). 설정하지 않았으면 빈 문자열. */
    public static function adminLoginId(): string
    {
        return strtolower(trim((string) self::get('admin_login_id', '')));
    }
}
