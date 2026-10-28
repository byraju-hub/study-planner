<?php
declare(strict_types=1);

// 웹 푸시용 VAPID 키 한 쌍을 만든다. 출력된 내용을 sp_private/config.php 에 붙여 넣는다. (한 번만 만들고 바꾸지 않는다)
//   php scripts/vapid-keys.php
require __DIR__ . '/../backend/src/bootstrap.php';

$k = App\Services\WebPush::generateVapidKeys();
echo "    'vapid' => [\n";
echo "        'subject' => 'mailto:본인이메일@example.com',\n";
echo "        'public' => '{$k['public']}',\n";
echo "        'private_pem' => '" . trim($k['private_pem']) . "',\n";
echo "    ],\n";
