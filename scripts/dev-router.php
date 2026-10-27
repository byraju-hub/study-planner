<?php
declare(strict_types=1);

// 개발용: php -S 127.0.0.1:8080 scripts/dev-router.php
// /sp/api/* 는 PHP API, 그 밖의 /sp/* 는 frontend/build 의 정적 파일(없으면 index.html).
$root = dirname(__DIR__);
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (str_starts_with($uri, '/sp/api')) {
    require $root . '/backend/public/index.php';
    return;
}

if (!str_starts_with($uri, '/sp')) {
    http_response_code(404);
    echo 'Not found. 화면은 /sp/ 에서 열립니다.';
    return;
}

$rel = substr($uri, 3);
$build = $root . '/frontend/build';
$file = realpath($build . $rel);
if ($rel === '' || $rel === '/' || $file === false || !is_file($file) || !str_starts_with($file, $build)) {
    $file = $build . '/index.html';
}
if (!is_file($file)) {
    http_response_code(503);
    echo '화면이 아직 빌드되지 않았습니다. cd frontend && npm run build';
    return;
}

$types = [
    'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
    'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
    'jpg' => 'image/jpeg', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'webmanifest' => 'application/manifest+json',
    'txt' => 'text/plain; charset=utf-8',
];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
readfile($file);
