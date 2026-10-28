<?php
declare(strict_types=1);

namespace App;

use App\Controllers\AdminController;
use App\Controllers\AssessmentController;
use App\Controllers\AuthController;
use App\Controllers\ExamController;
use App\Controllers\ExamPlanController;
use App\Controllers\ExamSubjectController;
use App\Controllers\JournalController;
use App\Controllers\MaterialController;
use App\Controllers\ReviewController;
use App\Controllers\SessionController;
use App\Controllers\StudyController;
use App\Controllers\SubjectController;
use App\Controllers\TaskController;
use App\Controllers\TextbookController;
use App\Controllers\TimetableController;
use App\Controllers\UnitController;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

final class App
{
    public static function run(string $root): void
    {
        $configPath = getenv('SP_CONFIG') ?: $root . '/config.php';
        $config = is_file($configPath) ? require $configPath : null;
        if (!is_array($config)) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => ['code' => 'config', 'message' => '서버 설정 파일이 없습니다.']], JSON_UNESCAPED_UNICODE);
            return;
        }
        $GLOBALS['sp_config'] = $config;
        $debug = (bool) ($config['debug'] ?? false);

        ini_set('display_errors', '0');
        date_default_timezone_set($config['timezone'] ?? 'Asia/Seoul');

        try {
            Db::init($config['db']);
            Session::start($config['session'] ?? []);
            $req = Request::fromGlobals($config['base_path'] ?? '');
            self::routes()->dispatch($req);
        } catch (HttpException $e) {
            $err = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->field !== null) {
                $err['field'] = $e->field;
            }
            Router::json(['ok' => false, 'error' => $err], $e->status);
        } catch (\Throwable $e) {
            error_log($e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $msg = $debug ? $e->getMessage() : '서버에 문제가 생겼어요. 잠시 뒤에 다시 시도해 주세요.';
            Router::json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => $msg]], 500);
        }
    }

    private static function routes(): Router
    {
        $r = new Router();

        $r->add('GET', '/auth/me', [AuthController::class, 'me'], false);
        $r->add('POST', '/auth/signup', [AuthController::class, 'signup'], false);
        $r->add('POST', '/auth/login', [AuthController::class, 'login'], false);
        $r->add('POST', '/auth/logout', [AuthController::class, 'logout'], false);
        $r->add('PUT', '/auth/password', [AuthController::class, 'changePassword']);
        $r->add('DELETE', '/auth/account', [AuthController::class, 'deleteAccount']);

        $r->add('GET', '/admin/users', [AdminController::class, 'users']);
        $r->add('GET', '/admin/stats', [AdminController::class, 'stats']);
        $r->add('POST', '/admin/users/{id}/status', [AdminController::class, 'setStatus']);
        $r->add('POST', '/admin/users/{id}/reset-password', [AdminController::class, 'resetPassword']);
        $r->add('DELETE', '/admin/users/{id}', [AdminController::class, 'deleteUser']);
        $r->add('GET', '/admin/settings', [AdminController::class, 'settings']);
        $r->add('PUT', '/admin/settings', [AdminController::class, 'updateSettings']);
        $r->add('GET', '/admin/log', [AdminController::class, 'logs']);
        $r->add('PUT', '/auth/profile', [AuthController::class, 'updateProfile']);

        $r->add('GET', '/subjects', [SubjectController::class, 'list']);
        $r->add('POST', '/subjects', [SubjectController::class, 'create']);
        $r->add('PUT', '/subjects/{id}', [SubjectController::class, 'update']);
        $r->add('DELETE', '/subjects/{id}', [SubjectController::class, 'delete']);

        $r->add('GET', '/textbooks', [TextbookController::class, 'list']);
        $r->add('POST', '/textbooks', [TextbookController::class, 'create']);
        $r->add('PUT', '/textbooks/{id}', [TextbookController::class, 'update']);
        $r->add('DELETE', '/textbooks/{id}', [TextbookController::class, 'delete']);

        $r->add('GET', '/units', [UnitController::class, 'list']);
        $r->add('POST', '/units', [UnitController::class, 'create']);
        $r->add('PUT', '/units/{id}', [UnitController::class, 'update']);
        $r->add('DELETE', '/units/{id}', [UnitController::class, 'delete']);

        $r->add('GET', '/materials', [MaterialController::class, 'list']);
        $r->add('POST', '/materials', [MaterialController::class, 'create']);
        $r->add('POST', '/materials/upload', [MaterialController::class, 'upload']);
        $r->add('GET', '/materials/{id}/file', [MaterialController::class, 'file']);
        $r->add('PUT', '/materials/{id}', [MaterialController::class, 'update']);
        $r->add('DELETE', '/materials/{id}', [MaterialController::class, 'delete']);

        $r->add('GET', '/tasks', [TaskController::class, 'list']);
        $r->add('POST', '/tasks', [TaskController::class, 'create']);
        $r->add('PUT', '/tasks/{id}', [TaskController::class, 'update']);
        $r->add('POST', '/tasks/{id}/toggle', [TaskController::class, 'toggle']);
        $r->add('DELETE', '/tasks/{id}', [TaskController::class, 'delete']);

        $r->add('GET', '/timetable', [TimetableController::class, 'get']);
        $r->add('PUT', '/timetable', [TimetableController::class, 'put']);

        $r->add('GET', '/reviews', [ReviewController::class, 'list']);
        $r->add('POST', '/reviews', [ReviewController::class, 'create']);
        $r->add('POST', '/reviews/{id}/done', [ReviewController::class, 'done']);
        $r->add('DELETE', '/reviews/{id}', [ReviewController::class, 'delete']);

        $r->add('GET', '/study/today', [StudyController::class, 'today']);
        $r->add('PUT', '/study/lessons', [StudyController::class, 'saveLesson']);

        $r->add('GET', '/sessions/active', [SessionController::class, 'active']);
        $r->add('POST', '/sessions', [SessionController::class, 'start']);
        $r->add('POST', '/sessions/{id}/stop', [SessionController::class, 'stop']);
        $r->add('GET', '/stats/week', [SessionController::class, 'week']);

        $r->add('GET', '/journal', [JournalController::class, 'get']);
        $r->add('PUT', '/journal', [JournalController::class, 'put']);

        $r->add('GET', '/exams', [ExamController::class, 'list']);
        $r->add('POST', '/exams', [ExamController::class, 'create']);
        $r->add('GET', '/exams/results', [ExamController::class, 'results']);
        $r->add('GET', '/exams/{id}', [ExamController::class, 'get']);
        $r->add('PUT', '/exams/{id}', [ExamController::class, 'update']);
        $r->add('DELETE', '/exams/{id}', [ExamController::class, 'delete']);
        $r->add('POST', '/exams/{id}/subjects', [ExamSubjectController::class, 'add']);
        $r->add('PUT', '/exam-subjects/{id}', [ExamSubjectController::class, 'update']);
        $r->add('DELETE', '/exam-subjects/{id}', [ExamSubjectController::class, 'delete']);
        $r->add('POST', '/exam-subjects/{id}/scopes', [ExamSubjectController::class, 'addScopes']);
        $r->add('PUT', '/exam-scopes/{id}', [ExamSubjectController::class, 'updateScope']);
        $r->add('DELETE', '/exam-scopes/{id}', [ExamSubjectController::class, 'deleteScope']);
        $r->add('POST', '/exams/{id}/plan/preview', [ExamPlanController::class, 'preview']);
        $r->add('POST', '/exams/{id}/plan/apply', [ExamPlanController::class, 'apply']);
        $r->add('GET', '/exams/{id}/tasks', [ExamPlanController::class, 'tasks']);

        $r->add('GET', '/assessments', [AssessmentController::class, 'list']);
        $r->add('POST', '/assessments', [AssessmentController::class, 'create']);
        $r->add('GET', '/assessments/{id}', [AssessmentController::class, 'get']);
        $r->add('PUT', '/assessments/{id}', [AssessmentController::class, 'update']);
        $r->add('DELETE', '/assessments/{id}', [AssessmentController::class, 'delete']);
        $r->add('POST', '/assessments/{id}/steps', [AssessmentController::class, 'addStep']);
        $r->add('PUT', '/assessment-steps/{id}', [AssessmentController::class, 'updateStep']);
        $r->add('POST', '/assessment-steps/{id}/toggle', [AssessmentController::class, 'toggleStep']);
        $r->add('DELETE', '/assessment-steps/{id}', [AssessmentController::class, 'deleteStep']);

        return $r;
    }
}
