<?php

declare(strict_types=1);

/**
 * ENV-bootstrap (vlucas/phpdotenv, 04.09):
 * централизованная загрузка .env (если файл существует) ДО чтения env
 * в production-коде. getenv() по всему коду остаётся единым интерфейсом —
 * dotenv с putenv=true наполняет процессное окружение.
 *
 * Иерархия приоритетов (dotenv НЕ перезаписывает существующие переменные):
 *   1. реальное окружение (export / putenv из tests / phpunit.xml)
 *   2. .env в корне репо
 *   3. .env.example как документация (НЕ загружается)
 *
 * Файл .env не требуется: при отсутствии всё работает как раньше.
 *
 * Использование: require __DIR__ . '/env_bootstrap.php';
 */

use Dotenv\Dotenv;

$repoRoot = __DIR__; // env_bootstrap.php живёт в корне репо
$envFile = $repoRoot . '/.env';

if (is_file($envFile)) {
    // immutable: реальное окружение имеет приоритет над .env
    $dotenv = Dotenv::createUnsafeImmutable($repoRoot);
    $dotenv->safeLoad();
}
