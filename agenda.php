<?php
// agenda.php v5 — Hive
date_default_timezone_set('Europe/Moscow');

// Error reporting: всё в stderr + лог-файл
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
ini_set('memory_limit', '8G');

require_once __DIR__ . '/vendor/autoload.php';

// Проверка: деплой через deploy.sh, не через прямой scp
$marker = __DIR__ . '/.deploy_ok';
if (! file_exists($marker)) {
    fwrite(STDERR, "DEPLOY BLOCKED: no .deploy_ok marker. Use scripts/deploy.sh\n");
    fwrite(STDERR, "  EMERGENCY: touch .deploy_ok && php agenda.php\n");
    exit(1);
}
$age = time() - filemtime($marker);
if ($age > 86400 * 7) {
    fwrite(STDERR, "DEPLOY WARNING: marker is {$age}s old (>7 days). Refresh with deploy.sh\n");
    // Не блокируем — только warning для долгоиграющих ульев
}

(new BeeSwarm\Hive\Hive())->run();
