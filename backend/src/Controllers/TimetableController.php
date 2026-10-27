<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Request;
use App\Core\Session;

final class TimetableController
{
    public const MAX_PERIOD = 8;

    public static function get(Request $req): array
    {
        $uid = Session::requireUser();
        $slots = Db::all(
            'SELECT t.weekday, t.period, t.subject_id, s.name AS subject_name, s.color AS subject_color
               FROM timetable_slots t JOIN subjects s ON s.id = t.subject_id
              WHERE t.user_id = ? ORDER BY t.weekday, t.period',
            [$uid]
        );
        return ['slots' => $slots, 'max_period' => self::MAX_PERIOD];
    }

    /** 시간표 전체를 보낸 내용으로 바꾼다. 과목이 null인 칸은 비운다. */
    public static function put(Request $req): array
    {
        $uid = Session::requireUser();
        $slots = $req->body['slots'] ?? null;
        if (!is_array($slots) || count($slots) > 5 * self::MAX_PERIOD) {
            throw new HttpException(422, 'validation', '시간표 형식이 올바르지 않습니다.', 'slots');
        }

        $rows = [];
        $seen = [];
        foreach ($slots as $s) {
            if (!is_array($s)) {
                throw new HttpException(422, 'validation', '시간표 형식이 올바르지 않습니다.', 'slots');
            }
            $weekday = (int) Input::int($s, 'weekday', 1, 5, '요일');
            $period = (int) Input::int($s, 'period', 1, self::MAX_PERIOD, '교시');
            $key = "$weekday-$period";
            if (isset($seen[$key])) {
                throw new HttpException(422, 'validation', '같은 요일·교시가 두 번 들어 있어요.', 'slots');
            }
            $seen[$key] = true;
            $subjectId = Input::ownedSubject($s, 'subject_id', $uid);
            if ($subjectId !== null) {
                $rows[] = [$uid, $weekday, $period, $subjectId];
            }
        }

        Db::transaction(static function () use ($uid, $rows): void {
            Db::run('DELETE FROM timetable_slots WHERE user_id = ?', [$uid]);
            foreach ($rows as $r) {
                Db::run('INSERT INTO timetable_slots (user_id, weekday, period, subject_id) VALUES (?, ?, ?, ?)', $r);
            }
        });
        return self::get($req);
    }
}
