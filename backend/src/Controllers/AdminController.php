<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Services\AiQuestions;
use App\Services\UserService;

/** 최고 관리자 전용. 회원 관리만 하고, 회원의 공부 내용·업로드 자료는 보여 주지 않는다. */
final class AdminController
{
    private const SIGNUP_MODES = ['open', 'approval', 'closed'];

    public static function users(Request $req): array
    {
        Session::requireAdmin();
        $where = [];
        $params = [];
        $status = (string) ($req->query['status'] ?? '');
        if (in_array($status, ['active', 'pending', 'blocked'], true)) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $q = trim((string) ($req->query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(u.login_id LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT u.id, u.login_id, u.email, u.name, u.school_name, u.grade, u.status,
                       u.created_at, u.last_login_at,
                       (SELECT COUNT(*) FROM sp_tasks t WHERE t.user_id = u.id) AS task_count,
                       (SELECT COUNT(*) FROM sp_materials m WHERE m.user_id = u.id) AS material_count,
                       (SELECT COALESCE(SUM(m.file_size), 0) FROM sp_materials m WHERE m.user_id = u.id) AS file_bytes
                  FROM sp_users u'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY u.status = 'pending' DESC, u.created_at DESC LIMIT 500";
        $admin = Config::adminLoginId();
        $rows = Db::all($sql, $params);
        foreach ($rows as &$r) {
            $r['is_admin'] = strtolower((string) $r['login_id']) === $admin;
        }
        unset($r);
        return ['users' => $rows];
    }

    public static function stats(Request $req): array
    {
        Session::requireAdmin();
        $by = Db::all('SELECT status, COUNT(*) AS n FROM sp_users GROUP BY status');
        $counts = ['active' => 0, 'pending' => 0, 'blocked' => 0];
        foreach ($by as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $bytes = (int) Db::one('SELECT COALESCE(SUM(file_size), 0) AS b FROM sp_materials')['b'];
        $recent = (int) Db::one(
            'SELECT COUNT(*) AS n FROM sp_users WHERE last_login_at > (NOW() - INTERVAL 7 DAY)'
        )['n'];
        return ['counts' => $counts, 'file_bytes' => $bytes, 'active_last_7_days' => $recent];
    }

    public static function setStatus(Request $req, array $p): array
    {
        $adminId = Session::requireAdmin();
        $target = self::target($p['id'], $adminId);
        $status = Input::enum($req->body, 'status', ['active', 'blocked'], '상태');
        $from = $target['status'];
        Db::run(
            'UPDATE sp_users SET status = ?, session_version = session_version + 1 WHERE id = ?',
            [$status, $target['id']]
        );
        $action = $status === 'blocked' ? 'block' : ($from === 'pending' ? 'approve' : 'unblock');
        self::record($adminId, $action, (int) $target['id'], $target['login_id']);
        return ['status' => $status];
    }

    public static function resetPassword(Request $req, array $p): array
    {
        $adminId = Session::requireAdmin();
        $target = self::target($p['id'], $adminId);
        $temp = UserService::temporaryPassword();
        Db::run(
            'UPDATE sp_users SET password_hash = ?, must_change_password = 1, session_version = session_version + 1 WHERE id = ?',
            [password_hash($temp, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT), $target['id']]
        );
        Db::run('DELETE FROM sp_login_attempts WHERE login_id = ?', [$target['login_id']]);
        self::record($adminId, 'reset_password', (int) $target['id'], $target['login_id']);
        return ['temporary_password' => $temp];
    }

    public static function deleteUser(Request $req, array $p): array
    {
        $adminId = Session::requireAdmin();
        $target = self::target($p['id'], $adminId);
        if (strtolower(trim((string) ($req->body['confirm_login_id'] ?? ''))) !== strtolower((string) $target['login_id'])) {
            throw new HttpException(422, 'validation', '삭제할 회원의 아이디를 정확히 입력해 주세요.', 'confirm_login_id');
        }
        UserService::delete((int) $target['id']);
        self::record($adminId, 'delete_user', null, $target['login_id']);
        return ['deleted' => true];
    }

    public static function settings(Request $req): array
    {
        Session::requireAdmin();
        return ['settings' => Settings::all()];
    }

    public static function updateSettings(Request $req): array
    {
        $adminId = Session::requireAdmin();
        $b = $req->body;
        $changes = [];
        if (array_key_exists('signup_mode', $b)) {
            $changes['signup_mode'] = Input::enum($b, 'signup_mode', self::SIGNUP_MODES, '가입 방식');
        }
        if (array_key_exists('ai_enabled', $b)) {
            $changes['ai_enabled'] = $b['ai_enabled'] ? '1' : '0';
        }
        if (array_key_exists('ai_model', $b)) {
            $changes['ai_model'] = Input::enum($b, 'ai_model', array_keys(AiQuestions::MODELS), 'AI 모델');
        }
        foreach ($changes as $name => $value) {
            Settings::set($name, $value);
            self::record($adminId, 'settings', null, "{$name}={$value}");
        }
        return ['settings' => Settings::all()];
    }

    public static function logs(Request $req): array
    {
        Session::requireAdmin();
        $rows = Db::all(
            'SELECT l.id, l.action, l.detail, l.created_at, a.login_id AS admin_login_id
               FROM sp_admin_log l LEFT JOIN sp_users a ON a.id = l.admin_id
              ORDER BY l.id DESC LIMIT 100'
        );
        return ['log' => $rows];
    }

    /** 관리 대상 회원. 관리자 본인은 대상이 될 수 없다. */
    private static function target(int $id, int $adminId): array
    {
        $row = Db::one('SELECT id, login_id, status FROM sp_users WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'not_found', '회원을 찾을 수 없어요.');
        }
        if ((int) $row['id'] === $adminId) {
            throw new HttpException(422, 'validation', '관리자 본인 계정은 여기서 바꿀 수 없어요.');
        }
        return $row;
    }

    private static function record(int $adminId, string $action, ?int $targetId, ?string $detail): void
    {
        Db::run(
            'INSERT INTO sp_admin_log (admin_id, action, target_user_id, detail) VALUES (?, ?, ?, ?)',
            [$adminId, $action, $targetId, $detail]
        );
    }
}
