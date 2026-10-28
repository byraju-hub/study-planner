<?php
declare(strict_types=1);

namespace App\Core;

/** 관리자가 바꾸는 사이트 설정 (sp_settings). */
final class Settings
{
    public const DEFAULTS = [
        'signup_mode' => 'open',   // open | approval | closed
        'ai_enabled' => '1',
    ];

    public static function get(string $name): string
    {
        $row = Db::one('SELECT value FROM sp_settings WHERE name = ?', [$name]);
        return $row === null ? (self::DEFAULTS[$name] ?? '') : (string) $row['value'];
    }

    public static function set(string $name, string $value): void
    {
        Db::run(
            'INSERT INTO sp_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $value]
        );
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        $out = self::DEFAULTS;
        foreach (Db::all('SELECT name, value FROM sp_settings') as $r) {
            if (array_key_exists($r['name'], self::DEFAULTS)) {
                $out[$r['name']] = (string) $r['value'];
            }
        }
        return $out;
    }
}
