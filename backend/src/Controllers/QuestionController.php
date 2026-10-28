<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;
use App\Services\QuizGrader;

/** 문제 은행: 학생이 직접 만든 문제(또는 AI 가 만들어 학생이 확인한 문제). 본인만 본다. */
final class QuestionController
{
    private const MAX_BULK = 50;
    private const COLUMNS = 'id, subject_id, unit_id, material_id, type, prompt, choices, answer, explanation, source, created_at';

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $sql = 'SELECT ' . self::COLUMNS . ' FROM sp_questions WHERE user_id = ?';
        $params = [$uid];
        foreach (['subject_id' => 'subject_id', 'unit_id' => 'unit_id'] as $q => $col) {
            if (isset($req->query[$q]) && $req->query[$q] !== '') {
                $sql .= " AND $col = ?";
                $params[] = (int) $req->query[$q];
            }
        }
        if (isset($req->query['type']) && isset(QuizGrader::TYPES[$req->query['type']])) {
            $sql .= ' AND type = ?';
            $params[] = $req->query['type'];
        }
        return array_map([self::class, 'present'], Db::all($sql . ' ORDER BY id DESC LIMIT 500', $params));
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $id = self::insert($uid, $req->body);
        return self::present(Owned::find('sp_questions', $id, $uid));
    }

    /** 여러 문제를 한 번에 (AI 가 만든 문제를 학생이 고른 뒤 저장할 때). 하나라도 잘못되면 전부 저장하지 않는다. */
    public static function bulk(Request $req): array
    {
        $uid = Session::requireUser();
        $items = $req->body['questions'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > self::MAX_BULK) {
            throw new HttpException(422, 'validation', '문제를 1~' . self::MAX_BULK . '개 보내 주세요.', 'questions');
        }
        $source = ($req->body['source'] ?? '') === 'ai' ? 'ai' : 'manual';
        $ids = Db::transaction(static function () use ($uid, $items, $source): array {
            $ids = [];
            foreach ($items as $i => $q) {
                if (!is_array($q)) {
                    throw new HttpException(422, 'validation', ($i + 1) . '번째 문제 형식이 올바르지 않아요.', 'questions');
                }
                try {
                    $ids[] = self::insert($uid, $q, $source);
                } catch (HttpException $e) {
                    throw new HttpException(422, 'validation', ($i + 1) . '번째 문제: ' . $e->getMessage(), 'questions');
                }
            }
            return $ids;
        });
        return ['created' => count($ids), 'ids' => $ids];
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_questions', $p['id'], $uid);
        $merged = $req->body + $row + ['choices' => null];
        if (is_string($merged['choices'])) {
            $merged['choices'] = json_decode($merged['choices'], true);
        }
        $clean = self::clean($merged);
        [$subjectId, $unitId] = self::place($uid, $merged);
        Db::run(
            'UPDATE sp_questions SET subject_id = ?, unit_id = ?, type = ?, prompt = ?, choices = ?, answer = ?, explanation = ? WHERE id = ? AND user_id = ?',
            [$subjectId, $unitId, $clean['type'], $clean['prompt'], self::json($clean['choices']), $clean['answer'], $clean['explanation'], $p['id'], $uid]
        );
        return self::present(Owned::find('sp_questions', $p['id'], $uid));
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('sp_questions', $p['id'], $uid);
        Db::run('DELETE FROM sp_questions WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        return ['deleted' => true];
    }

    /** @param array<string,mixed> $b */
    public static function insert(int $uid, array $b, string $source = 'manual'): int
    {
        $clean = self::clean($b);
        [$subjectId, $unitId] = self::place($uid, $b);
        $materialId = null;
        if (!empty($b['material_id'])) {
            $materialId = (int) Owned::find('sp_materials', (int) $b['material_id'], $uid)['id'];
        }
        return Db::insert(
            'INSERT INTO sp_questions (user_id, subject_id, unit_id, material_id, type, prompt, choices, answer, explanation, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $subjectId, $unitId, $materialId, $clean['type'], $clean['prompt'], self::json($clean['choices']), $clean['answer'], $clean['explanation'], $source]
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public static function present(array $row): array
    {
        $row['choices'] = is_string($row['choices'] ?? null) ? json_decode($row['choices'], true) : null;
        foreach (['id', 'subject_id', 'unit_id', 'material_id'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null) {
                $row[$k] = (int) $row[$k];
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $b */
    private static function clean(array $b): array
    {
        try {
            return QuizGrader::normalize($b);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, 'validation', $e->getMessage());
        }
    }

    /**
     * 과목·단원 확인. 단원을 고르면 그 단원이 속한 과목으로 맞춘다.
     * @param array<string,mixed> $b
     * @return array{0:int,1:?int}
     */
    private static function place(int $uid, array $b): array
    {
        $unitId = isset($b['unit_id']) && $b['unit_id'] !== '' && $b['unit_id'] !== null ? (int) $b['unit_id'] : null;
        if ($unitId !== null) {
            $row = Db::one(
                'SELECT t.subject_id FROM sp_units u JOIN sp_textbooks t ON t.id = u.textbook_id WHERE u.id = ? AND u.user_id = ?',
                [$unitId, $uid]
            );
            if ($row === null) {
                throw new HttpException(404, 'not_found', '단원을 찾을 수 없어요.');
            }
            return [(int) $row['subject_id'], $unitId];
        }
        $subjectId = Input::ownedSubject($b, 'subject_id', $uid, true);
        return [(int) $subjectId, null];
    }

    private static function json(?array $choices): ?string
    {
        return $choices === null ? null : json_encode($choices, JSON_UNESCAPED_UNICODE);
    }
}
