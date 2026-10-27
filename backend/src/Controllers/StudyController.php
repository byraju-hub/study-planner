<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Request;
use App\Core\Session;

/** 일반 스터디 모드: 하루 화면에 필요한 것을 한 번에 돌려주고, 당일 복습(수업 → 복습 등록)을 처리한다. */
final class StudyController
{
    public static function today(Request $req): array
    {
        $uid = Session::requireUser();
        $date = Input::date($req->query, 'date', '날짜', date('Y-m-d'));

        $minutes = Db::one(
            'SELECT COALESCE(SUM(minutes), 0) AS m FROM study_sessions
              WHERE user_id = ? AND ended_at IS NOT NULL AND DATE(started_at) = ?',
            [$uid, $date]
        )['m'];

        return [
            'date' => $date,
            'weekday' => (int) date('N', strtotime($date)),
            'tasks' => TaskController::fetch($uid, 't.task_date = ?', [$date]),
            'lessons' => self::lessons($uid, $date),
            'reviews_due' => ReviewController::fetch($uid, 'r.next_review_date <= ?', [$date], 'r.next_review_date, r.id', 100),
            'session' => SessionController::activeFor($uid),
            'server_now' => date('c'),
            'minutes_today' => (int) $minutes,
            'journal' => JournalController::find($uid, $date),
        ];
    }

    /** 오늘 수업(시간표)에 대한 복습 여부·내용을 저장한다. 완료로 바꾸면 1차 복습이 자동 등록된다. */
    public static function saveLesson(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $date = Input::date($b, 'date', '날짜', date('Y-m-d'));
        $period = (int) Input::int($b, 'period', 1, TimetableController::MAX_PERIOD, '교시');
        $topic = Input::str($b, 'topic', 1, 120, '배운 내용', false);
        $done = (bool) ($b['done'] ?? false);

        $weekday = (int) date('N', strtotime($date));
        $slot = Db::one(
            'SELECT t.subject_id, s.name FROM timetable_slots t JOIN subjects s ON s.id = t.subject_id
              WHERE t.user_id = ? AND t.weekday = ? AND t.period = ?',
            [$uid, $weekday, $period]
        );
        if ($slot === null) {
            throw new HttpException(422, 'validation', '시간표에 없는 수업이에요. 시간표를 먼저 입력해 주세요.', 'period');
        }

        Db::transaction(static function () use ($uid, $date, $period, $topic, $done, $slot): void {
            Db::run(
                'INSERT INTO lesson_reviews (user_id, lesson_date, period, subject_id, topic) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE subject_id = VALUES(subject_id), topic = VALUES(topic)',
                [$uid, $date, $period, $slot['subject_id'], $topic]
            );
            $lesson = Db::one(
                'SELECT id, done_at, review_item_id FROM lesson_reviews WHERE user_id = ? AND lesson_date = ? AND period = ?',
                [$uid, $date, $period]
            );

            if ($done && $lesson['done_at'] === null) {
                $title = $topic ?? "{$slot['name']} {$period}교시";
                $reviewId = ReviewController::add($uid, $title, (int) $slot['subject_id'], null, $date);
                Db::run('UPDATE lesson_reviews SET done_at = NOW(), review_item_id = ? WHERE id = ?', [$reviewId, $lesson['id']]);
            } elseif (!$done && $lesson['done_at'] !== null) {
                // 아직 한 번도 복습하지 않은 항목만 함께 지운다.
                if ($lesson['review_item_id'] !== null) {
                    Db::run('DELETE FROM review_items WHERE id = ? AND user_id = ? AND last_reviewed_at IS NULL', [$lesson['review_item_id'], $uid]);
                }
                Db::run('UPDATE lesson_reviews SET done_at = NULL, review_item_id = NULL WHERE id = ?', [$lesson['id']]);
            } elseif ($done && $topic !== null && $lesson['review_item_id'] !== null) {
                Db::run('UPDATE review_items SET title = ? WHERE id = ? AND user_id = ?', [$topic, $lesson['review_item_id'], $uid]);
            }
        });

        foreach (self::lessons($uid, $date) as $l) {
            if ($l['period'] === $period) {
                return $l;
            }
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private static function lessons(int $uid, string $date): array
    {
        $weekday = (int) date('N', strtotime($date));
        if ($weekday > 5) {
            return [];
        }
        $rows = Db::all(
            'SELECT t.period, t.subject_id, s.name AS subject_name, s.color AS subject_color,
                    l.topic, l.done_at, l.review_item_id
               FROM timetable_slots t
               JOIN subjects s ON s.id = t.subject_id
               LEFT JOIN lesson_reviews l ON l.user_id = t.user_id AND l.lesson_date = ? AND l.period = t.period
              WHERE t.user_id = ? AND t.weekday = ?
              ORDER BY t.period',
            [$date, $uid, $weekday]
        );
        return array_map(static function (array $r): array {
            $r['period'] = (int) $r['period'];
            $r['done'] = $r['done_at'] !== null;
            return $r;
        }, $rows);
    }
}
