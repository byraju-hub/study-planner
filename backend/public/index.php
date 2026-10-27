<?php
declare(strict_types=1);

// 서버에서 src/와 config.php를 웹 바깥(/sp_private 등)에 두려면 같은 폴더에 root.php를 만들어
// 그 경로를 돌려준다:  <?php return '/sp_private';
$root = is_file(__DIR__ . '/root.php') ? require __DIR__ . '/root.php' : dirname(__DIR__);

require $root . '/src/bootstrap.php';
App\App::run($root);
