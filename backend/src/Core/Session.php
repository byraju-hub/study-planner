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

    /**
     * 로그인한 사용자 id. 차단·승인 대기 중이거나, 비밀번호 초기화로 세션 버전이
     * 달라졌으면 로그아웃시키고 null.
     */
    public static function currentUserId(): ?int
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        $row = Db::one('SELECT status, session_version FROM sp_users WHERE id = ?', [$id]);
        if ($row === null || $row['status'] !== 'active' || (int) $row['session_version'] !== (int) ($_SESSION['ver'] ?? 0)) {
            self::logout();
            return null;
        }
        return $id;
    }

    public static function requireUser(): int
    {
        $id = self::currentUserId();
        if ($id === null) {
            throw new HttpException(401, 'unauthorized', '로그인이 필요합니다.');
        }
        return $id;
    }

    /** 최고 관리자만 통과. config.php 의 admin_login_id 와 같은 아이디여야 한다. */
    public static function requireAdmin(): int
    {
        $id = self::requireUser();
        $admin = Config::adminLoginId();
        $row = Db::one('SELECT login_id FROM sp_users WHERE id = ?', [$id]);
        if ($admin === '' || $row === null || strtolower((string) $row['login_id']) !== $admin) {
            throw new HttpException(403, 'forbidden', '관리자만 사용할 수 있어요.');
        }
        return $id;
    }

    public static function login(int $userId): void
    {
        $row = Db::one('SELECT session_version FROM sp_users WHERE id = ?', [$userId]);
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['ver'] = (int) ($row['session_version'] ?? 1);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    /** 이 세션은 유지하고 다른 기기의 세션은 모두 무효로 만든다. (비밀번호 변경 후) */
    public static function invalidateOthers(int $userId): void
    {
        Db::run('UPDATE sp_users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        $row = Db::one('SELECT session_version FROM sp_users WHERE id = ?', [$userId]);
        $_SESSION['ver'] = (int) $row['session_version'];
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
