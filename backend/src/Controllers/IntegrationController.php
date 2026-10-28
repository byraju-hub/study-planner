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
use App\Services\AiQuestions;
use App\Services\Aladin;
use App\Services\Neis;
use App\Services\QuizGrader;
use App\Services\TocParser;

/** 외부 연동: 서점 목차(알라딘), 학교·시간표(나이스), AI 문제 후보. 모두 학생이 확인한 뒤에만 저장한다. */
final class IntegrationController
{
    public static function status(Request $req): array
    {
        $uid = Session::requireUser();
        $consent = Db::one('SELECT ai_consent_at FROM sp_users WHERE id = ?', [$uid])['ai_consent_at'];
        return [
            'aladin' => Aladin::configured(),
            'neis' => Neis::configured(),
            'ai' => [
                'available' => AiQuestions::available() && Settings::get('ai_enabled') === '1',
                'consent' => $consent !== null,
                'remaining_today' => self::aiRemaining($uid),
            ],
        ];
    }

    public static function books(Request $req): array
    {
        Session::requireUser();
        $q = trim((string) ($req->query['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            throw new HttpException(422, 'validation', '검색어를 2글자 이상 입력해 주세요.', 'q');
        }
        return Aladin::search($q);
    }

    /** 목차를 가져와 단원 후보로 나눠 보여 준다. 저장은 importToc 에서. */
    public static function bookToc(Request $req): array
    {
        Session::requireUser();
        $isbn = (string) ($req->query['isbn'] ?? '');
        if (!preg_match('/^\d{13}$/', $isbn)) {
            throw new HttpException(422, 'validation', 'ISBN13 형식이 올바르지 않아요.', 'isbn');
        }
        $raw = Aladin::toc($isbn);
        return ['items' => TocParser::parse($raw), 'empty' => trim($raw) === ''];
    }

    /** 학생이 고친 목차 후보를 교재의 단원으로 저장한다. 단원 이름·쪽수만 저장한다. */
    public static function importToc(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $tb = Owned::find('sp_textbooks', $p['id'], $uid);
        $items = $req->body['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > TocParser::MAX_ITEMS) {
            throw new HttpException(422, 'validation', '단원을 1~' . TocParser::MAX_ITEMS . '개 보내 주세요.', 'items');
        }
        $created = Db::transaction(static function () use ($uid, $tb, $items): int {
            $order = (int) Db::one('SELECT COALESCE(MAX(sort_order), 0) AS n FROM sp_units WHERE textbook_id = ?', [$tb['id']])['n'];
            $parent = null;
            $n = 0;
            foreach ($items as $it) {
                if (!is_array($it)) {
                    throw new HttpException(422, 'validation', '단원 형식이 올바르지 않아요.', 'items');
                }
                $title = (string) Input::str($it, 'title', 1, 120, '단원 이름');
                $start = Input::int($it, 'page_start', 1, 9999, '시작 쪽', false);
                $end = Input::int($it, 'page_end', 1, 9999, '끝 쪽', false);
                if ($start !== null && $end !== null && $end < $start) {
                    $end = null;
                }
                $level = ((int) ($it['level'] ?? 1)) >= 2 && $parent !== null ? 2 : 1;
                $id = Db::insert(
                    'INSERT INTO sp_units (user_id, textbook_id, parent_id, title, page_start, page_end, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $tb['id'], $level === 2 ? $parent : null, $title, $start, $end, ++$order]
                );
                if ($level === 1) {
                    $parent = $id;
                }
                $n++;
            }
            return $n;
        });
        return ['created' => $created];
    }

    public static function schools(Request $req): array
    {
        Session::requireUser();
        $q = trim((string) ($req->query['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            throw new HttpException(422, 'validation', '학교 이름을 2글자 이상 입력해 주세요.', 'q');
        }
        return Neis::schools($q);
    }

    /**
     * 나이스 시간표를 내 과목에 맞춰 미리 보여 준다. 저장은 기존 PUT /timetable 로 학생이 확인한 뒤에.
     * 과목은 이름이 같거나 한쪽이 다른 쪽을 포함하면 짝지어 주고, 못 찾으면 subject_id 를 null 로 둔다.
     */
    public static function timetablePreview(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $office = (string) Input::str($b, 'office_code', 1, 20, '교육청');
        $school = (string) Input::str($b, 'school_code', 1, 20, '학교');
        $grade = (int) Input::int($b, 'grade', 1, 3, '학년');
        $class = (int) Input::int($b, 'class_no', 1, 40, '반');
        $slots = Neis::timetable($office, $school, $grade, $class, date('Y-m-d'));

        $subjects = Db::all('SELECT id, name FROM sp_subjects WHERE user_id = ?', [$uid]);
        $out = array_map(static function (array $s) use ($subjects): array {
            $s['subject_id'] = self::matchSubject($s['name'], $subjects);
            return $s;
        }, $slots);
        return ['slots' => $out, 'unmatched' => array_values(array_unique(array_column(array_filter($out, static fn(array $s): bool => $s['subject_id'] === null), 'name')))];
    }

    /** @param list<array{id:mixed,name:string}> $subjects */
    public static function matchSubject(string $name, array $subjects): ?int
    {
        $n = QuizGrader::norm($name);
        foreach ($subjects as $s) {
            if (QuizGrader::norm($s['name']) === $n) {
                return (int) $s['id'];
            }
        }
        foreach ($subjects as $s) {
            $sn = QuizGrader::norm($s['name']);
            if ($sn !== '' && (str_contains($n, $sn) || str_contains($sn, $n))) {
                return (int) $s['id'];
            }
        }
        return null;
    }

    public static function aiConsent(Request $req): array
    {
        $uid = Session::requireUser();
        if (empty($req->body['agree'])) {
            throw new HttpException(422, 'validation', '동의해야 AI 기능을 쓸 수 있어요.', 'agree');
        }
        Db::run('UPDATE sp_users SET ai_consent_at = COALESCE(ai_consent_at, NOW()) WHERE id = ?', [$uid]);
        return ['consent' => true];
    }

    /** 노트(또는 붙여 넣은 글)로 문제 후보를 만든다. 저장하지 않고 돌려준다. */
    public static function aiQuestions(Request $req): array
    {
        $uid = Session::requireUser();
        if (!AiQuestions::available() || Settings::get('ai_enabled') !== '1') {
            throw new HttpException(503, 'not_configured', 'AI 문제 만들기를 지금은 쓸 수 없어요.');
        }
        if (Db::one('SELECT ai_consent_at FROM sp_users WHERE id = ?', [$uid])['ai_consent_at'] === null) {
            throw new HttpException(403, 'consent_required', '먼저 AI 기능 사용에 동의해 주세요.');
        }
        $b = $req->body;
        $count = Input::int($b, 'count', 1, AiQuestions::MAX_COUNT, '문제 수', false) ?? 5;
        $types = array_values(array_intersect(array_keys(QuizGrader::TYPES), is_array($b['types'] ?? null) ? $b['types'] : ['mcq', 'ox', 'short']));
        if ($types === []) {
            throw new HttpException(422, 'validation', '문제 형식을 하나 이상 골라 주세요.', 'types');
        }

        $note = trim((string) ($b['text'] ?? ''));
        if ($note === '' && !empty($b['unit_id'])) {
            $unit = Owned::find('sp_units', (int) $b['unit_id'], $uid);
            $parts = array_column(Db::all(
                "SELECT m.title, m.content FROM sp_materials m
                  WHERE m.user_id = ? AND m.type = 'note' AND m.content IS NOT NULL
                    AND (m.unit_id = ? OR m.unit_id IN (SELECT id FROM sp_units WHERE parent_id = ?)) ORDER BY m.id",
                [$uid, $unit['id'], $unit['id']]
            ), 'content');
            $note = trim(implode("\n\n", $parts));
        }
        if (mb_strlen($note) < AiQuestions::MIN_TEXT_CHARS) {
            throw new HttpException(422, 'validation', '문제를 만들 노트가 너무 짧아요. 단원에 노트를 적거나 내용을 붙여 넣어 주세요. (최소 ' . AiQuestions::MIN_TEXT_CHARS . '자)');
        }

        self::reserveAiCall($uid);
        try {
            $questions = AiQuestions::generate($note, $count, $types);
        } catch (HttpException $e) {
            Db::run('UPDATE sp_ai_usage SET calls = GREATEST(calls - 1, 0) WHERE user_id = ? AND day = CURDATE()', [$uid]);
            throw $e;
        }
        return ['questions' => $questions, 'remaining_today' => self::aiRemaining($uid)];
    }

    private static function dailyLimit(): int
    {
        return (int) Config::get('anthropic.daily_limit', 10);
    }

    private static function aiRemaining(int $uid): int
    {
        $row = Db::one('SELECT calls FROM sp_ai_usage WHERE user_id = ? AND day = CURDATE()', [$uid]);
        return max(0, self::dailyLimit() - (int) ($row['calls'] ?? 0));
    }

    /** 호출 전에 하루 사용 횟수를 올린다. 한도를 넘으면 429. */
    private static function reserveAiCall(int $uid): void
    {
        Db::run('INSERT INTO sp_ai_usage (user_id, day, calls) VALUES (?, CURDATE(), 0) ON DUPLICATE KEY UPDATE calls = calls', [$uid]);
        $ok = Db::run('UPDATE sp_ai_usage SET calls = calls + 1 WHERE user_id = ? AND day = CURDATE() AND calls < ?', [$uid, self::dailyLimit()]);
        if ($ok === 0) {
            throw new HttpException(429, 'limit', '오늘 AI 문제 만들기를 모두 사용했어요. 내일 다시 시도해 주세요.');
        }
    }
}
