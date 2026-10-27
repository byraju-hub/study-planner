<?php
declare(strict_types=1);

// 사용: php scripts/migrate.php   (다른 설정은 SP_CONFIG=경로)
$root = dirname(__DIR__);
$configPath = getenv('SP_CONFIG') ?: $root . '/backend/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "설정 파일이 없습니다: $configPath\n");
    exit(1);
}
$db = (require $configPath)['db'];

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'] ?? 3306, $db['name']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob($root . '/db/migrations/*.sql') ?: [];
sort($files);

$applied = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) {
        continue;
    }
    echo "적용: $name\n";
    // 문장 단위로 실행해야 중간 문장의 오류가 누락되지 않는다.
    $statements = preg_split('/;\s*(?:\r?\n|$)/', (string) file_get_contents($file));
    foreach ($statements as $sql) {
        if (trim($sql) !== '') {
            $pdo->exec($sql);
        }
    }
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
    $applied++;
}

echo $applied === 0 ? "적용할 마이그레이션이 없습니다.\n" : "완료: {$applied}개 적용\n";
