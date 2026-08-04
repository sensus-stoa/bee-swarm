#!/usr/bin/env php
<?php
/**
 * scripts/deploy-version.php — защита от scp в обход deploy.sh
 *
 * deploy.sh создаёт .deploy_ok после успешных тестов.
 * agenda.php проверяет файл при старте.
 * Прямой scp → нет .deploy_ok → hive не стартует.
 *
 * Использование:
 *   php scripts/deploy-version.php write   # deploy.sh: записать метку
 *   php scripts/deploy-version.php check   # agenda.php: проверить
 */

declare(strict_types=1);

$marker = __DIR__ . '/../.deploy_ok';

if (($argv[1] ?? '') === 'write') {
    $hash = trim(shell_exec('git rev-parse HEAD 2>/dev/null') ?: 'unknown');
    $data = json_encode([
        'commit' => $hash,
        'timestamp' => date('c'),
        'host' => gethostname(),
    ], JSON_THROW_ON_ERROR);
    file_put_contents($marker, $data);
    echo "DEPLOY MARKER: {$hash}\n";
    exit(0);
}

if (($argv[1] ?? '') === 'check') {
    if (! file_exists($marker)) {
        fwrite(STDERR, "DEPLOY BLOCKED: no .deploy_ok marker.\n");
        fwrite(STDERR, "  Use scripts/deploy.sh to deploy safely.\n");
        fwrite(STDERR, "  EMERGENCY: touch .deploy_ok to bypass.\n");
        exit(1);
    }
    $data = json_decode(file_get_contents($marker), true);
    $age = time() - strtotime($data['timestamp']);
    echo "DEPLOY OK: commit={$data['commit']} age={$age}s\n";
    exit(0);
}

fwrite(STDERR, "Usage: php scripts/deploy-version.php [write|check]\n");
exit(1);
