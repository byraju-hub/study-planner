<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(array $cfg): void
    {
        $lifetime = (int) ($cfg['lifetime'] ?? 1209600);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_name($cfg['name'] ?? 'SPSESS');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public static function requireUser(): int
    {
        $id = self::userId();
        if ($id === null) {
            throw new HttpException(401, 'unauthorized', '로그인이 필요합니다.');
        }
        return $id;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function csrf(): string
    {
        return (string) $_SESSION['csrf'];
    }

    public static function verifyCsrf(Request $req): void
    {
        $sent = $req->header('X-CSRF-Token');
        if ($sent === '' || !hash_equals(self::csrf(), $sent)) {
            throw new HttpException(403, 'csrf', '요청을 확인할 수 없습니다. 페이지를 새로고침해 주세요.');
        }
    }
}
