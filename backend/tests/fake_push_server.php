<?php
// 테스트용 가짜 푸시 서비스. 받은 요청(헤더·본문)을 PUSH_LOG 파일에 한 줄 JSON 으로 쌓는다.
//   PUSH_LOG=/tmp/push.log php -S 127.0.0.1:8099 backend/tests/fake_push_server.php
$entry = [
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'encoding' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
    'ttl' => $_SERVER['HTTP_TTL'] ?? '',
    'body' => base64_encode(file_get_contents('php://input')),
];
file_put_contents(getenv('PUSH_LOG') ?: '/tmp/push.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(str_contains($entry['path'], 'gone') ? 410 : 201);
