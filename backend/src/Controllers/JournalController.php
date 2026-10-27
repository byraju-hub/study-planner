<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Input;
use App\Core\Request;
use App\Core\Session;

final class JournalController
{
    public static function get(Request $req): array
    {
        $uid = Session::requireUser();
        $date = Input::date($req->query, 'date', '날짜', date('Y-m-d'));
        return ['journal' => self::find($uid, $date)];
    }

    public static function put(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $date = Input::date($b, 'date', '날짜', date('Y-m-d'));
        $good = Input::text($b, 'good', 2000, '잘한 점');
        $bad = Input::text($b, 'bad', 2000, '아쉬운 점');
        $rating = Input::int($b, 'self_rating', 1, 5, '자기 평가', false);

        Db::run(
            'INSERT INTO sp_daily_journals (user_id, journal_date, good, bad, self_rating) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE good = VALUES(good), bad = VALUES(bad), self_rating = VALUES(self_rating)',
            [$uid, $date, $good, $bad, $rating]
        );
        return ['journal' => self::find($uid, $date)];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $uid, string $date): ?array
    {
        return Db::one(
            'SELECT journal_date AS date, good, bad, self_rating FROM sp_daily_journals WHERE user_id = ? AND journal_date = ?',
            [$uid, $date]
        );
    }
}
