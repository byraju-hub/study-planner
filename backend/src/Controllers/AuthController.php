<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Services\UserService;

final class AuthController
{
    private const MAX_FAILS_PER_LOGIN = 5;
    private const MAX_FAILS_PER_IP = 30;

    public static function me(Request $req): array
    {
        $uid = Session::currentUserId();
        return [
            'user' => $uid === null ? null : self::publicUser($uid),
            'csrf' => Session::csrf(),
            'signup_mode' => Settings::get('signup_mode'),
        ];
    }

    public static function signup(Request $req): array
    {
        $b = $req->body;
        $loginId = strtolower((string) Input::str($b, 'login_id', 4, 20, '아이디'));
        if (!preg_match('/^[a-z0-9_]+$/', $loginId)) {
            throw new HttpException(422, 'validation', '아이디는 영문 소문자, 숫자, _만 쓸 수 있어요.', 'login_id');
        }
        $email = (string) Input::str($b, 'email', 5, 190, '이메일');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'validation', '이메일 형식이 올바르지 않습니다.', 'email');
        }
        $password = self::password($b, 'password', '비밀번호');
        if ($password === $loginId) {
            throw new HttpException(422, 'validation', '비밀번호는 아이디와 달라야 해요.', 'password');
        }
        $name = (string) Input::str($b, 'name', 1, 30, '이름');
        $school = Input::str($b, 'school_name', 1, 100, '학교', false);
        $grade = Input::int($b, 'grade', 1, 3, '학년', false);

        // 관리자 아이디는 가입 방식과 상관없이 바로 활성화한다.
        $isAdmin = $loginId === Config::adminLoginId();
        $mode = Settings::get('signup_mode');
        if ($mode === 'closed' && !$isAdmin) {
            throw new HttpException(403, 'signup_closed', '지금은 새로 가입할 수 없어요. 관리자에게 문의해 주세요.');
        }
        $status = ($mode === 'approval' && !$isAdmin) ? 'pending' : 'active';

        try {
            $uid = Db::insert(
                'INSERT INTO sp_users (login_id, email, password_hash, status, name, school_name, grade) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$loginId, $email, self::hash($password), $status, $name, $school, $grade]
            );
        } catch (\PDOException $e) {
            if (Owned::isDuplicate($e)) {
                $field = str_contains($e->getMessage(), 'uq_users_email') ? 'email' : 'login_id';
                $msg = $field === 'email' ? '이미 가입된 이메일입니다.' : '이미 사용 중인 아이디입니다.';
                throw new HttpException(409, 'duplicate', $msg, $field);
            }
            throw $e;
        }

        SubjectController::seedDefaults($uid);
        if ($status === 'pending') {
            return ['pending' => true, 'csrf' => Session::csrf()];
        }
        Session::login($uid);
        return ['user' => self::publicUser($uid), 'csrf' => Session::csrf()];
    }

    public static function login(Request $req): array
    {
        $loginId = strtolower(trim((string) ($req->body['login_id'] ?? '')));
        $password = (string) ($req->body['password'] ?? '');
        if ($loginId === '' || $password === '') {
            throw new HttpException(422, 'validation', '아이디와 비밀번호를 입력해 주세요.');
        }
        $ip = $req->ip();
        self::assertNotLocked($loginId, $ip);

        $user = Db::one('SELECT id, password_hash, status FROM sp_users WHERE login_id = ?', [$loginId]);
        // 없는 아이디도 같은 시간이 걸리도록 더미 해시를 검증한다.
        $hash = $user['password_hash'] ?? self::hash('dummy-password-1');
        $ok = password_verify($password, $hash) && $user !== null;

        Db::run('INSERT INTO sp_login_attempts (login_id, ip, success) VALUES (?, ?, ?)', [$loginId, $ip, $ok ? 1 : 0]);
        if (random_int(1, 50) === 1) {
            Db::run('DELETE FROM sp_login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
        if (!$ok) {
            throw new HttpException(401, 'bad_credentials', '아이디 또는 비밀번호가 올바르지 않습니다.');
        }

        // 비밀번호가 맞은 뒤에만 상태를 알려 준다. (아이디 존재 여부를 추측하지 못하게)
        if ($user['status'] === 'pending') {
            throw new HttpException(403, 'pending', '가입 승인을 기다리고 있어요. 관리자가 승인하면 로그인할 수 있어요.');
        }
        if ($user['status'] === 'blocked') {
            throw new HttpException(403, 'blocked', '이용이 제한된 계정이에요. 관리자에게 문의해 주세요.');
        }

        $uid = (int) $user['id'];
        if (password_needs_rehash($user['password_hash'], self::algo())) {
            Db::run('UPDATE sp_users SET password_hash = ? WHERE id = ?', [self::hash($password), $uid]);
        }
        Db::run('UPDATE sp_users SET last_login_at = NOW() WHERE id = ?', [$uid]);
        Session::login($uid);
        return ['user' => self::publicUser($uid), 'csrf' => Session::csrf()];
    }

    public static function logout(Request $req): array
    {
        Session::logout();
        return ['csrf' => Session::csrf()];
    }

    public static function changePassword(Request $req): array
    {
        $uid = Session::requireUser();
        $current = (string) ($req->body['current_password'] ?? '');
        $new = self::password($req->body, 'new_password', '새 비밀번호');

        $row = Db::one('SELECT password_hash FROM sp_users WHERE id = ?', [$uid]);
        if ($row === null || !password_verify($current, $row['password_hash'])) {
            throw new HttpException(422, 'validation', '현재 비밀번호가 올바르지 않습니다.', 'current_password');
        }
        Db::run('UPDATE sp_users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [self::hash($new), $uid]);
        Session::invalidateOthers($uid);
        return ['changed' => true];
    }

    /** 회원 탈퇴: 비밀번호를 다시 확인하고 모든 데이터를 지운다. 관리자 계정은 탈퇴할 수 없다. */
    public static function deleteAccount(Request $req): array
    {
        $uid = Session::requireUser();
        $row = Db::one('SELECT login_id, password_hash FROM sp_users WHERE id = ?', [$uid]);
        if ($row === null || !password_verify((string) ($req->body['password'] ?? ''), $row['password_hash'])) {
            throw new HttpException(422, 'validation', '비밀번호가 올바르지 않습니다.', 'password');
        }
        if (strtolower((string) $row['login_id']) === Config::adminLoginId()) {
            throw new HttpException(422, 'validation', '관리자 계정은 탈퇴할 수 없어요.');
        }
        UserService::delete($uid);
        Session::logout();
        return ['deleted' => true, 'csrf' => Session::csrf()];
    }

    public static function updateProfile(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $name = (string) Input::str($b, 'name', 1, 30, '이름');
        $school = Input::str($b, 'school_name', 1, 100, '학교', false);
        $grade = Input::int($b, 'grade', 1, 3, '학년', false);
        $class = Input::int($b, 'class_no', 1, 30, '반', false);
        Db::run(
            'UPDATE sp_users SET name = ?, school_name = ?, grade = ?, class_no = ? WHERE id = ?',
            [$name, $school, $grade, $class, $uid]
        );
        return ['user' => self::publicUser($uid)];
    }

    private static function password(array $d, string $key, string $label): string
    {
        $pw = $d[$key] ?? null;
        if (!is_string($pw) || strlen($pw) < 8 || strlen($pw) > 72) {
            throw new HttpException(422, 'validation', "{$label}는 8~72자로 입력해 주세요.", $key);
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            throw new HttpException(422, 'validation', "{$label}에는 영문자와 숫자가 모두 있어야 해요.", $key);
        }
        return $pw;
    }

    private static function assertNotLocked(string $loginId, string $ip): void
    {
        $byLogin = (int) Db::one(
            'SELECT COUNT(*) AS n FROM sp_login_attempts
              WHERE login_id = ? AND success = 0 AND created_at > (NOW() - INTERVAL 10 MINUTE)',
            [$loginId]
        )['n'];
        $byIp = (int) Db::one(
            'SELECT COUNT(*) AS n FROM sp_login_attempts
              WHERE ip = ? AND success = 0 AND created_at > (NOW() - INTERVAL 10 MINUTE)',
            [$ip]
        )['n'];
        if ($byLogin >= self::MAX_FAILS_PER_LOGIN || $byIp >= self::MAX_FAILS_PER_IP) {
            throw new HttpException(429, 'too_many_attempts', '로그인에 여러 번 실패했어요. 10분 뒤에 다시 시도해 주세요.');
        }
    }

    private static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    /** @return array<string,mixed> */
    private static function publicUser(int $uid): array
    {
        $u = Db::one(
            'SELECT id, login_id, email, name, school_name, school_code, office_code, grade, class_no, must_change_password FROM sp_users WHERE id = ?',
            [$uid]
        );
        if ($u === null) {
            throw new HttpException(401, 'unauthorized', '로그인이 필요합니다.');
        }
        $u['must_change_password'] = (bool) $u['must_change_password'];
        $admin = Config::adminLoginId();
        $u['is_admin'] = $admin !== '' && strtolower((string) $u['login_id']) === $admin;
        return $u;
    }
}
