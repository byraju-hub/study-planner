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
    // 최고 관리자 아이디. 이 아이디로 가입/로그인한 사람만 /admin 을 쓸 수 있다. 비워 두면 관리자 없음.
    'admin_login_id' => '',
    'session' => [
        'name' => 'SPSESS',
        'lifetime' => 1209600, // 14일
    ],
    // 웹에서 직접 접근할 수 없는 폴더 권장.
    'upload_dir' => __DIR__ . '/uploads',
    'upload_max_bytes' => 10 * 1024 * 1024,
    // 알림 생성을 외부 크론이 불러 주는 주소의 비밀 토큰: /sp/api/cron/notify?token=...  (비워 두면 꺼짐)
    'cron_token' => '',
    // 웹 푸시(휴대폰·브라우저 알림). `php scripts/vapid-keys.php` 로 만든 값을 넣는다. 없으면 앱 안 알림함만 쓴다.
    // 'vapid' => ['subject' => 'mailto:본인이메일', 'public' => '...', 'private_pem' => '...'],
    // 외부 연동 (없으면 해당 기능만 꺼진다). 키는 각 서비스에서 발급받는다.
    // 'aladin' => ['ttb_key' => '알라딘 TTB 키'],
    // 'neis' => ['key' => '나이스 교육정보 개방 포털 인증키'],
    // 'anthropic' => ['api_key' => 'sk-ant-...', 'model' => 'claude-haiku-5-5', 'daily_limit' => 10],
    'debug' => false,
];
