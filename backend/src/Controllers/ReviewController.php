<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;

/**
 * 망각곡선 복습. 처음 공부한 날 다음 날 1차 복습이 잡히고,
 * 복습을 마칠 때마다 3 → 7 → 14 → 30일 뒤로 간격이 늘어난다. 5차를 마치면 끝.
 * "어려웠어요"를 고르면 단계를 유지하고 내일 다시 잡는다.
 */
final class ReviewController
{
    /** 단계별 복습 간격(일). 단계 k의 복습은 직전 복습 후 INTERVALS[k-1]일 뒤. */
    public const INTERVALS = [1, 3, 7, 14, 30];

    private const SELECT = 'SELECT r.id, r.subject_id, s.name AS subject_name, s.color AS subject_color,
                                   r.unit_id, r.title, r.stage, r.next_review_date, r.last_reviewed_at
                              FROM sp_review_items r LEFT JOIN sp_subjects s ON s.id = r.subject_id';

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $scope = Input::enum($req->query, 'scope', ['due', 'upcoming'], '구분', 'due');
        $today = date('Y-m-d');
        $where = $scope === 'due' ? 'r.next_review_date <= ?' : 'r.next_review_date > ?';
        $limit = $scope === 'due' ? 100 : 30;
        return self::fetch($uid, $where, [$today], 'r.next_review_date, r.id', $limit);
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $title = (string) Input::str($b, 'title', 1, 120, '복습할 내용');
        $subjectId = Input::ownedSubject($b, 'subject_id', $uid);
        $unitId = null;
        if (Input::int($b, 'unit_id', 1, PHP_INT_MAX, '단원', false) !== null) {
            $unitId = (int) Owned::find('sp_units', (int) $b['unit_id'], $uid)['id'];
        }
        $id = self::add($uid, $title, $subjectId, $unitId, date('Y-m-d'));
        return self::fetch($uid, 'r.id = ?', [$id], 'r.id', 1)[0];
    }

    public static function done(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_review_items', $p['id'], $uid);
        if ($row['next_review_date'] === null) {
            throw new HttpException(409, 'finished', '이미 복습을 모두 마친 항목이에요.');
        }
        $hard = (bool) ($req->body['hard'] ?? false);
        $stage = (int) $row['stage'];
        $last = count(self::INTERVALS);

        if ($hard) {
            $newStage = $stage;
            $next = self::addDays(date('Y-m-d'), 1);
        } elseif ($stage >= $last) {
            $newStage = $stage;
            $next = null;
        } else {
            $newStage = $stage + 1;
            $next = self::addDays(date('Y-m-d'), self::INTERVALS[$newStage - 1]);
        }

        Db::run(
            'UPDATE sp_review_items SET stage = ?, next_review_date = ?, last_reviewed_at = NOW() WHERE id = ? AND user_id = ?',
            [$newStage, $next, $row['id'], $uid]
        );
        return self::fetch($uid, 'r.id = ?', [(int) $row['id']], 'r.id', 1, false)[0] + ['finished' => $next === null];
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('sp_review_items', $p['id'], $uid);
        Db::run('DELETE FROM sp_review_items WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        return ['deleted' => true];
    }

    /** 처음 공부한 날($baseDate)을 기준으로 1차 복습을 등록한다. */
    public static function add(int $uid, string $title, ?int $subjectId, ?int $unitId, string $baseDate): int
    {
        return Db::insert(
            'INSERT INTO sp_review_items (user_id, subject_id, unit_id, title, stage, next_review_date) VALUES (?, ?, ?, ?, 1, ?)',
            [$uid, $subjectId, $unitId, $title, self::addDays($baseDate, self::INTERVALS[0])]
        );
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /** @return list<array<string,mixed>> */
    public static function fetch(int $uid, string $where, array $params, string $order, int $limit, bool $activeOnly = true): array
    {
        $active = $activeOnly ? 'AND r.next_review_date IS NOT NULL' : '';
        $rows = Db::all(
            self::SELECT . " WHERE r.user_id = ? $active AND $where ORDER BY $order LIMIT " . (int) $limit,
            [$uid, ...$params]
        );
        $today = new \DateTimeImmutable(date('Y-m-d'));
        return array_map(static function (array $r) use ($today): array {
            $r['interval_days'] = ReviewController::INTERVALS[(int) $r['stage'] - 1];
            $r['overdue_days'] = 0;
            if ($r['next_review_date'] !== null) {
                $due = new \DateTimeImmutable($r['next_review_date']);
                $r['overdue_days'] = max(0, (int) $due->diff($today)->format('%r%a'));
            }
            return $r;
        }, $rows);
    }
}
