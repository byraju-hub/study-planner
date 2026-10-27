<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** 핸들러가 직접 응답을 보냈을 때(파일 다운로드 등) 돌려주는 값 */
    public const STREAMED = '__streamed__';

    /** @var list<array{method:string,regex:string,handler:callable,auth:bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'auth' => $auth];
    }

    public function dispatch(Request $req): void
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $req->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $req->method) {
                continue;
            }
            if ($req->method !== 'GET') {
                Session::verifyCsrf($req);
            }
            if ($route['auth']) {
                Session::requireUser();
            }
            $params = array_map('intval', array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
            $result = ($route['handler'])($req, $params);
            if ($result !== self::STREAMED) {
                self::json(['ok' => true, 'data' => $result]);
            }
            return;
        }
        if ($pathMatched) {
            throw new HttpException(405, 'method_not_allowed', '허용되지 않는 요청입니다.');
        }
        throw new HttpException(404, 'not_found', '찾을 수 없는 주소입니다.');
    }

    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
