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
use App\Services\NotificationService;
use App\Services\WebPush;

final class NotificationController
{
    public static function index(Request $req): array
    {
        $uid = Session::requireUser();
        NotificationService::generate($uid);
        $rows = Db::all(
            'SELECT id, kind, title, body, link, read_at, created_at FROM sp_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [$uid]
        );
        $unread = (int) Db::one('SELECT COUNT(*) AS n FROM sp_notifications WHERE user_id = ? AND read_at IS NULL', [$uid])['n'];
        return ['notifications' => $rows, 'unread' => $unread];
    }

    public static function read(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('sp_notifications', $p['id'], $uid);
        Db::run('UPDATE sp_notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL', [$p['id'], $uid]);
        return ['read' => true];
    }

    public static function readAll(Request $req): array
    {
        $uid = Session::requireUser();
        Db::run('UPDATE sp_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
        return ['read' => true];
    }

    public static function settings(Request $req): array
    {
        $uid = Session::requireUser();
        return [
            'settings' => NotificationService::settings($uid),
            'push' => [
                'available' => NotificationService::pushConfigured(),
                'public_key' => NotificationService::pushConfigured() ? (string) Config::get('vapid.public') : null,
                'subscriptions' => (int) Db::one('SELECT COUNT(*) AS n FROM sp_push_subscriptions WHERE user_id = ?', [$uid])['n'],
            ],
        ];
    }

    public static function updateSettings(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $morning = self::time($b, 'morning_time');
        $evening = ($b['evening_time'] ?? '') === '' ? null : self::time($b, 'evening_time');
        Db::run(
            'INSERT INTO sp_notification_settings (user_id, tasks_on, review_on, exam_on, assessment_on, morning_time, evening_time)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tasks_on = VALUES(tasks_on), review_on = VALUES(review_on), exam_on = VALUES(exam_on),
                                     assessment_on = VALUES(assessment_on), morning_time = VALUES(morning_time), evening_time = VALUES(evening_time)',
            [$uid, (int) !empty($b['tasks_on']), (int) !empty($b['review_on']), (int) !empty($b['exam_on']), (int) !empty($b['assessment_on']), $morning, $evening]
        );
        return ['settings' => NotificationService::settings($uid)];
    }

    public static function subscribe(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $endpoint = (string) Input::str($b, 'endpoint', 10, 1000, '주소');
        $keys = is_array($b['keys'] ?? null) ? $b['keys'] : [];
        $p256dh = (string) Input::str($keys, 'p256dh', 20, 120, '키');
        $auth = (string) Input::str($keys, 'auth', 8, 60, '키');
        if (!WebPush::endpointAllowed($endpoint, (bool) Config::get('push_allow_any_endpoint', false))) {
            throw new HttpException(422, 'validation', '허용되지 않는 푸시 주소예요.', 'endpoint');
        }
        if (strlen(WebPush::unb64u($p256dh)) !== 65 || strlen(WebPush::unb64u($auth)) !== 16) {
            throw new HttpException(422, 'validation', '푸시 키 형식이 올바르지 않아요.');
        }
        Db::run(
            'INSERT INTO sp_push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)',
            [$uid, hash('sha256', $endpoint), $endpoint, $p256dh, $auth]
        );
        return ['subscribed' => true];
    }

    public static function unsubscribe(Request $req): array
    {
        $uid = Session::requireUser();
        $endpoint = (string) ($req->body['endpoint'] ?? '');
        Db::run('DELETE FROM sp_push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$uid, hash('sha256', $endpoint)]);
        return ['subscribed' => false];
    }

    /** 외부 크론이 주기적으로 부르는 주소. config.php 의 cron_token 과 같아야 한다. */
    public static function cron(Request $req): array
    {
        $token = (string) Config::get('cron_token', '');
        if ($token === '' || !hash_equals($token, (string) ($req->query['token'] ?? ''))) {
            throw new HttpException(403, 'forbidden', '허용되지 않은 요청이에요.');
        }
        return NotificationService::generateForAll();
    }

    private static function time(array $b, string $key): string
    {
        $v = $b[$key] ?? null;
        if (!is_string($v) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
            throw new HttpException(422, 'validation', '시각은 HH:MM 형식으로 입력해 주세요.', $key);
        }
        return $v;
    }
}
