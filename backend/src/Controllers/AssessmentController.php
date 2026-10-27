<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Input;
use App\Core\Owned;
use App\Core\Request;
use App\Core\Session;
use App\Services\AssessmentPlan;

/**
 * 수행평가와 단계 체크리스트.
 * 목표일이 있는 단계는 "오늘 할 일"(tasks, source=assessment)에도 자동으로 들어가고, 완료 여부가 양쪽에서 같이 바뀐다.
 */
final class AssessmentController
{
    private const STATUSES = ['planned', 'in_progress', 'submitted', 'graded'];

    /** 마감이 시험 시작 며칠 전부터 시험 끝날 때까지면 "시험 기간과 가까움" 경고를 띄운다. */
    private const EXAM_NEAR_DAYS = 7;

    public static function list(Request $req): array
    {
        $uid = Session::requireUser();
        $rows = Db::all(
            'SELECT a.id, a.subject_id, s.name AS subject_name, s.color AS subject_color, a.title, a.kind, a.max_score,
                    a.weight_pct, a.due_date, a.status, a.score,
                    (SELECT COUNT(*) FROM sp_assessment_steps st WHERE st.assessment_id = a.id) AS step_total,
                    (SELECT COUNT(*) FROM sp_assessment_steps st WHERE st.assessment_id = a.id AND st.done_at IS NOT NULL) AS step_done
               FROM sp_assessments a JOIN sp_subjects s ON s.id = a.subject_id
              WHERE a.user_id = ?',
            [$uid]
        );
        $today = date('Y-m-d');
        $exams = self::upcomingExams($uid, $today);

        $rows = array_map(static function (array $r) use ($today, $exams): array {
            $r['step_total'] = (int) $r['step_total'];
            $r['step_done'] = (int) $r['step_done'];
            $r['d_day'] = ExamController::daysUntil($r['due_date'], $today);
            $r['kind_label'] = AssessmentPlan::KINDS[$r['kind']];
            $r['exam_warning'] = self::examWarning($r, $exams);
            return $r;
        }, $rows);

        usort($rows, static function (array $a, array $b): int {
            $aDone = in_array($a['status'], ['submitted', 'graded'], true);
            $bDone = in_array($b['status'], ['submitted', 'graded'], true);
            if ($aDone !== $bDone) {
                return $aDone <=> $bDone;
            }
            return $aDone ? $b['due_date'] <=> $a['due_date'] : [$a['due_date'], $a['id']] <=> [$b['due_date'], $b['id']];
        });
        return $rows;
    }

    public static function create(Request $req): array
    {
        $uid = Session::requireUser();
        $b = $req->body;
        $f = self::fields($b, $uid, null);
        $today = date('Y-m-d');

        $id = Db::transaction(static function () use ($uid, $f, $b, $today): int {
            $id = Db::insert(
                'INSERT INTO sp_assessments (user_id, subject_id, title, kind, max_score, weight_pct, due_date, rubric)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, $f['subject_id'], $f['title'], $f['kind'], $f['max_score'], $f['weight_pct'], $f['due_date'], $f['rubric']]
            );
            if ((bool) ($b['auto_steps'] ?? false)) {
                $assessment = Owned::find('sp_assessments', $id, $uid);
                foreach (AssessmentPlan::steps($f['kind'], $today, $f['due_date']) as $i => $step) {
                    $stepId = Db::insert(
                        'INSERT INTO sp_assessment_steps (user_id, assessment_id, title, target_date, sort_order) VALUES (?, ?, ?, ?, ?)',
                        [$uid, $id, $step['title'], $step['target_date'], $i + 1]
                    );
                    self::syncTask($uid, $assessment, Owned::findStep($stepId, $uid));
                }
            }
            return $id;
        });
        return self::detail($uid, $id);
    }

    public static function get(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('sp_assessments', $p['id'], $uid);
        return self::detail($uid, $p['id']);
    }

    public static function update(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $row = Owned::find('sp_assessments', $p['id'], $uid);
        $f = self::fields($req->body, $uid, $row);
        Db::transaction(static function () use ($uid, $row, $f): void {
            Db::run(
                'UPDATE sp_assessments SET subject_id = ?, title = ?, kind = ?, max_score = ?, weight_pct = ?, due_date = ?, rubric = ?, status = ?, score = ?
                  WHERE id = ? AND user_id = ?',
                [$f['subject_id'], $f['title'], $f['kind'], $f['max_score'], $f['weight_pct'], $f['due_date'], $f['rubric'], $f['status'], $f['score'], $row['id'], $uid]
            );
            // 제목·과목이 바뀌면 단계별 할 일의 제목·과목도 맞춘다.
            $assessment = Owned::find('sp_assessments', (int) $row['id'], $uid);
            foreach (Db::all('SELECT * FROM sp_assessment_steps WHERE assessment_id = ? AND user_id = ?', [$row['id'], $uid]) as $step) {
                self::syncTask($uid, $assessment, $step);
            }
        });
        return self::detail($uid, (int) $row['id']);
    }

    public static function delete(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        Owned::find('sp_assessments', $p['id'], $uid);
        Db::transaction(static function () use ($uid, $p): void {
            Db::run(
                "DELETE FROM sp_tasks WHERE user_id = ? AND source = 'assessment'
                   AND ref_id IN (SELECT id FROM sp_assessment_steps WHERE assessment_id = ? AND user_id = ?)",
                [$uid, $p['id'], $uid]
            );
            Db::run('DELETE FROM sp_assessments WHERE id = ? AND user_id = ?', [$p['id'], $uid]);
        });
        return ['deleted' => true];
    }

    public static function addStep(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $assessment = Owned::find('sp_assessments', $p['id'], $uid);
        $b = $req->body;
        $title = (string) Input::str($b, 'title', 1, 80, '단계 이름');
        $date = array_key_exists('target_date', $b) && $b['target_date'] !== null ? Input::date($b, 'target_date', '목표일') : null;
        $next = (int) Db::one('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM sp_assessment_steps WHERE assessment_id = ?', [$assessment['id']])['n'];

        Db::transaction(static function () use ($uid, $assessment, $title, $date, $next): void {
            $id = Db::insert(
                'INSERT INTO sp_assessment_steps (user_id, assessment_id, title, target_date, sort_order) VALUES (?, ?, ?, ?, ?)',
                [$uid, $assessment['id'], $title, $date, $next]
            );
            self::syncTask($uid, $assessment, Owned::findStep($id, $uid));
        });
        return self::detail($uid, (int) $assessment['id']);
    }

    public static function updateStep(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $step = Owned::findStep($p['id'], $uid);
        $assessment = Owned::find('sp_assessments', (int) $step['assessment_id'], $uid);
        $b = $req->body;
        $title = Input::str($b, 'title', 1, 80, '단계 이름', false) ?? $step['title'];
        $date = array_key_exists('target_date', $b) ? ($b['target_date'] === null ? null : Input::date($b, 'target_date', '목표일')) : $step['target_date'];
        $order = Input::int($b, 'sort_order', 0, 100000, '순서', false) ?? (int) $step['sort_order'];

        Db::transaction(static function () use ($uid, $step, $assessment, $title, $date, $order): void {
            Db::run('UPDATE sp_assessment_steps SET title = ?, target_date = ?, sort_order = ? WHERE id = ? AND user_id = ?', [$title, $date, $order, $step['id'], $uid]);
            self::syncTask($uid, $assessment, Owned::findStep((int) $step['id'], $uid));
        });
        return self::detail($uid, (int) $assessment['id']);
    }

    public static function toggleStep(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $step = Owned::findStep($p['id'], $uid);
        $done = array_key_exists('done', $req->body) ? (bool) $req->body['done'] : $step['done_at'] === null;
        self::setStepDone($uid, $step, $done);
        return self::detail($uid, (int) $step['assessment_id']);
    }

    public static function deleteStep(Request $req, array $p): array
    {
        $uid = Session::requireUser();
        $step = Owned::findStep($p['id'], $uid);
        Db::transaction(static function () use ($uid, $step): void {
            Db::run("DELETE FROM sp_tasks WHERE user_id = ? AND source = 'assessment' AND ref_id = ?", [$uid, $step['id']]);
            Db::run('DELETE FROM sp_assessment_steps WHERE id = ? AND user_id = ?', [$step['id'], $uid]);
        });
        return self::detail($uid, (int) $step['assessment_id']);
    }

    /** 단계 완료 여부를 바꾸고 연결된 할 일에도 반영한다. 첫 단계를 끝내면 "예정"이 "진행중"이 된다. */
    public static function setStepDone(int $uid, array $step, bool $done): void
    {
        Db::transaction(static function () use ($uid, $step, $done): void {
            Db::run('UPDATE sp_assessment_steps SET done_at = ' . ($done ? 'COALESCE(done_at, NOW())' : 'NULL') . ' WHERE id = ? AND user_id = ?', [$step['id'], $uid]);
            Db::run(
                "UPDATE sp_tasks SET done_at = " . ($done ? 'COALESCE(done_at, NOW())' : 'NULL') . " WHERE user_id = ? AND source = 'assessment' AND ref_id = ?",
                [$uid, $step['id']]
            );
            if ($done) {
                Db::run("UPDATE sp_assessments SET status = 'in_progress' WHERE id = ? AND user_id = ? AND status = 'planned'", [$step['assessment_id'], $uid]);
            }
        });
    }

    /** 할 일에서 단계 체크를 바꿨을 때 호출된다. */
    public static function onTaskToggled(int $uid, array $task, bool $done): void
    {
        $step = Db::one('SELECT * FROM sp_assessment_steps WHERE id = ? AND user_id = ?', [$task['ref_id'], $uid]);
        if ($step !== null) {
            self::setStepDone($uid, $step, $done);
        }
    }

    /** 할 일을 지우면 그 단계의 목표일도 비운다. (할 일이 다시 생기지 않도록) */
    public static function onTaskDeleted(int $uid, array $task): void
    {
        Db::run('UPDATE sp_assessment_steps SET target_date = NULL WHERE id = ? AND user_id = ?', [$task['ref_id'], $uid]);
    }

    /** 할 일의 날짜를 옮기면 단계의 목표일도 옮긴다. */
    public static function onTaskMoved(int $uid, array $task, string $newDate): void
    {
        Db::run('UPDATE sp_assessment_steps SET target_date = ? WHERE id = ? AND user_id = ?', [$newDate, $task['ref_id'], $uid]);
    }

    /** 단계 ↔ 할 일 맞추기: 목표일이 없으면 할 일을 지우고, 있으면 만들거나 고친다. */
    private static function syncTask(int $uid, array $assessment, array $step): void
    {
        $existing = Db::one("SELECT id FROM sp_tasks WHERE user_id = ? AND source = 'assessment' AND ref_id = ?", [$uid, $step['id']]);
        if ($step['target_date'] === null) {
            if ($existing !== null) {
                Db::run('DELETE FROM sp_tasks WHERE id = ? AND user_id = ?', [$existing['id'], $uid]);
            }
            return;
        }
        $title = "{$assessment['title']} · {$step['title']}";
        $title = mb_strlen($title) > 120 ? mb_substr($title, 0, 119) . '…' : $title;
        $doneSql = $step['done_at'] !== null ? $step['done_at'] : null;

        if ($existing === null) {
            Db::run(
                "INSERT INTO sp_tasks (user_id, subject_id, source, ref_id, title, task_date, done_at, sort_order) VALUES (?, ?, 'assessment', ?, ?, ?, ?, 200)",
                [$uid, $assessment['subject_id'], $step['id'], $title, $step['target_date'], $doneSql]
            );
        } else {
            Db::run(
                'UPDATE sp_tasks SET subject_id = ?, title = ?, task_date = ?, done_at = ? WHERE id = ? AND user_id = ?',
                [$assessment['subject_id'], $title, $step['target_date'], $doneSql, $existing['id'], $uid]
            );
        }
    }

    /** @return array<string,mixed> */
    private static function detail(int $uid, int $id): array
    {
        $a = Db::one(
            'SELECT a.id, a.subject_id, s.name AS subject_name, s.color AS subject_color, a.title, a.kind, a.max_score, a.weight_pct,
                    a.due_date, a.rubric, a.status, a.score
               FROM sp_assessments a JOIN sp_subjects s ON s.id = a.subject_id
              WHERE a.id = ? AND a.user_id = ?',
            [$id, $uid]
        );
        if ($a === null) {
            throw new HttpException(404, 'not_found', '찾을 수 없는 수행평가입니다.');
        }
        $today = date('Y-m-d');
        $a['d_day'] = ExamController::daysUntil($a['due_date'], $today);
        $a['kind_label'] = AssessmentPlan::KINDS[$a['kind']];
        $a['exam_warning'] = self::examWarning($a, self::upcomingExams($uid, $today));
        $a['steps'] = array_map(static function (array $s): array {
            $s['done'] = $s['done_at'] !== null;
            return $s;
        }, Db::all(
            'SELECT id, title, target_date, done_at, sort_order FROM sp_assessment_steps WHERE assessment_id = ? AND user_id = ? ORDER BY sort_order, id',
            [$id, $uid]
        ));
        return $a;
    }

    /** @return list<array<string,mixed>> */
    private static function upcomingExams(int $uid, string $today): array
    {
        return Db::all('SELECT id, title, start_date, end_date FROM sp_exams WHERE user_id = ? AND end_date >= ? ORDER BY start_date', [$uid, $today]);
    }

    /** 마감이 시험 기간(시험 시작 7일 전 ~ 종료일)과 겹치면 안내 문구를 돌려준다. */
    private static function examWarning(array $a, array $exams): ?string
    {
        if (in_array($a['status'], ['submitted', 'graded'], true)) {
            return null;
        }
        foreach ($exams as $e) {
            $from = (new \DateTimeImmutable($e['start_date']))->modify('-' . self::EXAM_NEAR_DAYS . ' days')->format('Y-m-d');
            if ($a['due_date'] >= $from && $a['due_date'] <= $e['end_date']) {
                return "마감이 {$e['title']} 기간과 겹치거나 가까워요. 일정을 앞당겨 보세요.";
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $b
     * @param array<string,mixed>|null $cur
     * @return array<string,mixed>
     */
    private static function fields(array $b, int $uid, ?array $cur): array
    {
        $subjectId = array_key_exists('subject_id', $b) || $cur === null
            ? (int) Input::ownedSubject($b, 'subject_id', $uid, true)
            : (int) $cur['subject_id'];
        $title = Input::str($b, 'title', 1, 120, '수행평가 이름', $cur === null) ?? $cur['title'];
        $kind = Input::enum($b, 'kind', array_keys(AssessmentPlan::KINDS), '종류', $cur['kind'] ?? 'report');
        $max = array_key_exists('max_score', $b) ? Input::int($b, 'max_score', 1, 1000, '배점', false) : ($cur['max_score'] ?? null);
        $weight = array_key_exists('weight_pct', $b) ? Input::int($b, 'weight_pct', 0, 100, '반영 비율', false) : ($cur['weight_pct'] ?? null);
        $due = Input::date($b, 'due_date', '마감일', $cur['due_date'] ?? null);
        $rubric = array_key_exists('rubric', $b) ? Input::text($b, 'rubric', 5000, '평가 기준') : ($cur['rubric'] ?? null);
        $status = Input::enum($b, 'status', self::STATUSES, '상태', $cur['status'] ?? 'planned');

        $score = null;
        if (array_key_exists('score', $b)) {
            $v = $b['score'];
            if ($v !== null && $v !== '') {
                if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000) {
                    throw new HttpException(422, 'validation', '점수는 0 이상의 숫자로 입력해 주세요.', 'score');
                }
                $score = round((float) $v, 1);
            }
        } elseif ($cur !== null && $cur['score'] !== null) {
            $score = (float) $cur['score'];
        }
        if ($score !== null && $max !== null && $score > $max) {
            throw new HttpException(422, 'validation', '점수는 배점보다 클 수 없어요.', 'score');
        }
        return [
            'subject_id' => $subjectId, 'title' => $title, 'kind' => $kind, 'max_score' => $max, 'weight_pct' => $weight,
            'due_date' => $due, 'rubric' => $rubric, 'status' => $status, 'score' => $score,
        ];
    }
}
