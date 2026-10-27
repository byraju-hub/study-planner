<?php
// 복사해서 config.php로 저장하고 값을 채운다. config.php는 커밋하지 않는다.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'study_planner',
        'user' => 'study_planner',
        'pass' => 'CHANGE_ME',
    ],
    // 요청 경로에서 이 접두어를 떼고 라우팅한다.
    'base_path' => '/sp/api',
    'timezone' => 'Asia/Seoul',
    'session' => [
        'name' => 'SPSESS',
        'lifetime' => 1209600, // 14일
    ],
    // 웹에서 직접 접근할 수 없는 폴더 권장.
    'upload_dir' => __DIR__ . '/uploads',
    'upload_max_bytes' => 10 * 1024 * 1024,
    'debug' => false,
];
