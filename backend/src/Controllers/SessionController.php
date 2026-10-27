<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

/** 공부 타이머. 시작·종료 시각은 서버 시계를 기준으로 한다. */
final class SessionController
{
    /** 종료를 깜빡하고 지나간 세션이 비정상적으로 길어지지 않게 하는 상한(분). */
    private const MAX_MINUTES = 720;

    public static function active(Request $req): array
    {
        $uid = Session::requireUser();
        return ['session' => self::activeFor($uid), 'server_now' => date('c')];
    }

    public static function start(Request $req): array
    {
        $uid = Session::requireUser();
        $subjectId = Input::ownedSubject($req->body, 'subject_id', $uid);
        if (self::activeFor($uid) !== null) {
            throw new HttpException(409, 'already_running', '이미 공부 중이에요. 먼저 정지해 주세요.');
        }
        $id = Db::insert('INSERT INTO study_sessions (user_id, subject_id, started_at) VALUES (?, ?, NOW())', [$uid, $subjectId]);
        return ['session' => self::fetch($uid, $id), 'server_now' => date('c')];
    }

    public static function stop(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('study_sessions', $p['id'], $uid);
        if ($row['ended_at'] !== null) {
            throw new HttpException(409, 'already_stopped', '이미 정지한 기록이에요.');
        }
        Db::run(
            'UPDATE study_sessions SET ended_at = NOW(), minutes = LEAST(TIMESTAMPDIFF(MINUTE, started_at, NOW()), ?)
              WHERE id = ? AND user_id = ?',
            [self::MAX_MINUTES, $row['id'], $uid]
        );
        return ['session' => self::fetch($uid, (int) $row['id'])];
    }

    /** 최근 7일(오늘 포함)의 날짜별·과목별 공부 시간(분). */
    public static function week(Request $req): array
    {
        $uid = Session::requireUser();
        $today = date('Y-m-d');
        $from = ReviewController::addDays($today, -6);

        $byDay = [];
        foreach (Db::all(
            'SELECT DATE(started_at) AS d, SUM(minutes) AS m FROM study_sessions
              WHERE user_id = ? AND ended_at IS NOT NULL AND DATE(started_at) BETWEEN ? AND ? GROUP BY d',
            [$uid, $from, $today]
        ) as $r) {
            $byDay[$r['d']] = (int) $r['m'];
        }
        $days = [];
        $total = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = ReviewController::addDays($from, $i);
            $m = $byDay[$d] ?? 0;
            $total += $m;
            $days[] = ['date' => $d, 'minutes' => $m];
        }

        $subjects = Db::all(
            'SELECT s.id AS subject_id, COALESCE(s.name, \'과목 없음\') AS name, COALESCE(s.color, \'#5B6475\') AS color, SUM(w.minutes) AS minutes
               FROM study_sessions w LEFT JOIN subjects s ON s.id = w.subject_id
              WHERE w.user_id = ? AND w.ended_at IS NOT NULL AND DATE(w.started_at) BETWEEN ? AND ?
              GROUP BY s.id, s.name, s.color ORDER BY minutes DESC',
            [$uid, $from, $today]
        );
        foreach ($subjects as &$s) {
            $s['minutes'] = (int) $s['minutes'];
        }
        return ['days' => $days, 'total_minutes' => $total, 'subjects' => $subjects];
    }

    /** @return array<string,mixed>|null */
    public static function activeFor(int $uid): ?array
    {
        $r = Db::one('SELECT id FROM study_sessions WHERE user_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1', [$uid]);
        return $r === null ? null : self::fetch($uid, (int) $r['id']);
    }

    /** @return array<string,mixed> */
    private static function fetch(int $uid, int $id): array
    {
        $r = Db::one(
            'SELECT w.id, w.subject_id, s.name AS subject_name, s.color AS subject_color, w.started_at, w.ended_at, w.minutes
               FROM study_sessions w LEFT JOIN subjects s ON s.id = w.subject_id
              WHERE w.id = ? AND w.user_id = ?',
            [$id, $uid]
        ) ?? [];
        foreach (['started_at', 'ended_at'] as $k) {
            if (isset($r[$k])) {
                $r[$k] = date('c', strtotime($r[$k]));
            }
        }
        return $r;
    }
}
