#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_null.php — System Null-Calibration (§0.7)
 *
 * Генерирует 200 shuffle-прогонов на ≥15 доменах.
 * FPR_system должен быть равен 0.
 * Exit 0 = PASS, Exit 1 = FAIL.
 *
 * Использование: php scripts/verify/verify_0_null.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;

// Калибровочные домены: 10 синтетических + 5 с заведомо отсутствующей структурой
$domains = [];

// Домен 1: y = x0 + x1 (аддитивный)
$domains[] = generateDomain('add', function ($x0, $x1) {
    return $x0 + $x1;
}, 20);

// Домен 2: y = x0 × x1 (мультипликативный)
$domains[] = generateDomain('mul', function ($x0, $x1) {
    return $x0 * $x1;
}, 20);

// Домен 3: y = x0² (квадратичный)
$domains[] = generateDomain('sq', function ($x0) {
    return $x0 * $x0;
}, 15, 1);

// Домен 4: y = max(x0, x1)
$domains[] = generateDomain('max', function ($x0, $x1) {
    return max($x0, $x1);
}, 20);

// Домен 5: y = x0 − x1
$domains[] = generateDomain('sub', function ($x0, $x1) {
    return $x0 - $x1;
}, 20);

// Домены 6-10: линейные с разным числом признаков
for ($f = 1; $f <= 5; $f++) {
    $domains[] = generateDomain("linear_{$f}", function (...$args) {
        return array_sum($args);
    }, 15, $f);
}

// Домены 11-15: чистый шум (y_i ~ Uniform(0,1), x_i ~ Uniform(0,1))
for ($i = 0; $i < 5; $i++) {
    $n = mt_rand(10, 50);
    $f = mt_rand(2, 5);
    $X = [];
    $y = [];
    for ($j = 0; $j < $n; $j++) {
        $row = [];
        for ($k = 0; $k < $f; $k++) {
            $row[] = mt_rand(0, 1000) / 1000.0;
        }
        $X[] = $row;
        $y[] = mt_rand(0, 1000) / 1000.0;
    }
    $domains[] = ['X' => $X, 'y' => $y];
}

$result = AtomRegistry::runNullCalibration($domains, 200);

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

if ($result['pass']) {
    echo "PASS: FPR_system = 0 ({$result['false_discoveries']}/{$result['trials']})\n";
    exit(0);
} else {
    echo "FAIL: FPR_system = {$result['fpr']} ({$result['false_discoveries']}/{$result['trials']})\n";
    exit(1);
}

function generateDomain(string $name, callable $fn, int $n, int $features = 2): array
{
    $X = [];
    $y = [];
    for ($i = 0; $i < $n; $i++) {
        $args = [];
        for ($j = 0; $j < $features; $j++) {
            $args[] = mt_rand(1, 100);
        }
        $X[] = array_map('floatval', $args);
        $y[] = (float) $fn(...$args);
    }
    return ['X' => $X, 'y' => $y];
}
