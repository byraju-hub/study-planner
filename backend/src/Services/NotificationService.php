<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;

final class NotificationService
{
    /** @return array<string,mixed> */
    public static function settings(int $userId): array
    {
        $row = Db::one('SELECT tasks_on, review_on, exam_on, assessment_on, morning_time, evening_time FROM sp_notification_settings WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return NotificationPlanner::DEFAULT_SETTINGS;
        }
        foreach (['tasks_on', 'review_on', 'exam_on', 'assessment_on'] as $k) {
            $row[$k] = (bool) $row[$k];
        }
        return $row;
    }

    /** 지금 시점에 필요한 알림을 만든다. 이미 만든 것은 건너뛰고, 새로 만든 개수를 돌려준다. */
    public static function generate(int $userId, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();
        $today = $now->format('Y-m-d');
        $plan = NotificationPlanner::plan([
            'today' => $today,
            'time' => $now->format('H:i'),
            'settings' => self::settings($userId),
            'tasks' => array_map(static fn(array $t): array => [
                'title' => (string) $t['title'],
                'done' => $t['done_at'] !== null,
                'est_minutes' => $t['est_minutes'] === null ? null : (int) $t['est_minutes'],
            ], Db::all('SELECT title, done_at, est_minutes FROM sp_tasks WHERE user_id = ? AND task_date = ? ORDER BY sort_order, id', [$userId, $today])),
            'reviews' => array_column(Db::all('SELECT title FROM sp_review_items WHERE user_id = ? AND next_review_date IS NOT NULL AND next_review_date <= ? ORDER BY next_review_date, id', [$userId, $today]), 'title'),
            'exams' => array_map(static fn(array $e): array => ['id' => (int) $e['id'], 'title' => (string) $e['title'], 'start_date' => (string) $e['start_date']],
                Db::all('SELECT id, title, start_date FROM sp_exams WHERE user_id = ? AND start_date BETWEEN ? AND ? + INTERVAL 7 DAY', [$userId, $today, $today])),
            'assessments' => array_map(static fn(array $a): array => ['id' => (int) $a['id'], 'title' => (string) $a['title'], 'due_date' => (string) $a['due_date'], 'status' => (string) $a['status']],
                Db::all("SELECT id, title, due_date, status FROM sp_assessments WHERE user_id = ? AND status IN ('planned','in_progress') AND due_date BETWEEN ? - INTERVAL 3 DAY AND ? + INTERVAL 3 DAY", [$userId, $today, $today])),
        ]);

        $created = 0;
        foreach ($plan as $n) {
            $new = Db::run(
                'INSERT IGNORE INTO sp_notifications (user_id, kind, title, body, link, dedupe_key) VALUES (?, ?, ?, ?, ?, ?)',
                [$userId, $n['kind'], $n['title'], $n['body'], $n['link'], $n['dedupe_key']]
            );
            if ($new > 0) {
                $created++;
                self::push($userId, $n);
            }
        }
        return $created;
    }

    /** 모든 활성 회원의 알림을 만든다 (크론용). @return array{users:int,created:int} */
    public static function generateForAll(?\DateTimeImmutable $now = null): array
    {
        $created = 0;
        $ids = Db::all("SELECT id FROM sp_users WHERE status = 'active'");
        foreach ($ids as $r) {
            $created += self::generate((int) $r['id'], $now);
        }
        return ['users' => count($ids), 'created' => $created];
    }

    public static function pushConfigured(): bool
    {
        return (string) Config::get('vapid.public', '') !== '' && (string) Config::get('vapid.private_pem', '') !== '';
    }

    /** @param array{title:string,body:?string,link:string} $n */
    private static function push(int $userId, array $n): void
    {
        if (!self::pushConfigured()) {
            return;
        }
        $vapid = (array) Config::get('vapid');
        foreach (Db::all('SELECT id, endpoint, p256dh, auth FROM sp_push_subscriptions WHERE user_id = ?', [$userId]) as $sub) {
            try {
                $status = WebPush::send($sub, ['title' => $n['title'], 'body' => $n['body'], 'link' => $n['link']], $vapid);
            } catch (\Throwable $e) {
                error_log('push: ' . $e->getMessage());
                continue;
            }
            if ($status === 404 || $status === 410) {
                Db::run('DELETE FROM sp_push_subscriptions WHERE id = ?', [$sub['id']]);
            }
        }
    }
}
