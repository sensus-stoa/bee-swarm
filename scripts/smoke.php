#!/usr/bin/env php
<?php
/**
 * scripts/smoke.php — PRODUCTION SMOKE TEST
 *
 * Запускается на production-машине после деплоя (SSH+SCP или вручную).
 * Быстрый (< 5 секунд). Проверяет критические инварианты, ломающиеся
 * при state leakage и RNG poisoning.
 *
 * Использование:
 *   php scripts/smoke.php          # quick: 5s
 *   php scripts/smoke.php --full   # full: 30s (50 ticks)
 *
 * Exit codes: 0 = PASS, 1 = FAIL
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\TaskGenerator;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Infra\RngIsolation;

$full = in_array('--full', $argv ?? [], true);
$ticks = $full ? 50 : 15;

$errors = [];

// ── TEST 1: RNG clean baseline ──────────────────────────
echo "[SMOKE] RNG clean baseline... ";
if (RngIsolation::hasUnrestoredGuards()) {
    echo "FAIL (unrestored RNG guards at startup!)\n";
    $errors[] = 'Unrestored RNG guards at baseline';
} else {
    echo "OK\n";
}

// ── TEST 2: TaskGenerator restore RNG ──────────────────
echo "[SMOKE] TaskGenerator RNG restore... ";
$gen = new TaskGenerator();
$gen->createComposeTasks();
if (RngIsolation::hasUnrestoredGuards()) {
    echo "FAIL (createComposeTasks left unrestored RNG guard!)\n";
    $errors[] = 'TaskGenerator::createComposeTasks leaks RNG state';
} else {
    echo "OK\n";
}

// ── TEST 3: Hive bootstrap + tick loop ─────────────────
echo "[SMOKE] Hive {$ticks}-tick run... ";
try {
    $logFile = tempnam(sys_get_temp_dir(), 'smoke_');
    $plateau = new PlateauDetector(50, plateauSleepUs: 0);
    $hive = new Hive(plateau: $plateau, maxTicks: $ticks, logFile: $logFile);

    // Перенаправляем stdout в /dev/null чтобы не спамить
    ob_start();
    $result = $hive->run();
    ob_end_clean();

    $log = file_exists($logFile) ? file_get_contents($logFile) : '';
    unlink($logFile);

    if ($result < $ticks) {
        echo "FAIL (expected {$ticks} ticks, got {$result})\n";
        $errors[] = "Hive stopped early at tick {$result}";
    } else {
        echo "OK ({$result} ticks)\n";
    }
} catch (\Throwable $e) {
    echo "FAIL ({$e->getMessage()})\n";
    $errors[] = "Hive crashed: {$e->getMessage()}";
}

// ── TEST 4: RNG clean after full run ───────────────────
echo "[SMOKE] RNG clean after run... ";
if (RngIsolation::hasUnrestoredGuards()) {
    echo "FAIL (unrestored RNG guards after Hive::run()!)\n";
    $errors[] = 'Unrestored RNG guards after full Hive run — srand() leak in tick loop';
} else {
    echo "OK\n";
}

// ── TEST 5: Discoveries made ───────────────────────────
echo "[SMOKE] Discoveries in log... ";
$discoveries = substr_count($log, '🔍');
echo "{$discoveries} discovery(s) ";

// Минимальный порог: хотя бы что-то за $ticks тиков
// (полный цикл 50 тиков должен давать ≥2)
if ($full && $discoveries < 2) {
    echo "FAIL\n";
    $errors[] = "Only {$discoveries} discoveries in {$ticks} ticks (full mode expect ≥2)";
} else {
    echo "OK\n";
}

// ── TEST 6: Bee population alive ───────────────────────
echo "[SMOKE] Bee population... ";
$bees = $hive->getBees();
$alive = count(array_filter($bees, fn ($b) => $b->isAlive()));
if ($alive === 0) {
    echo "FAIL (all bees dead)\n";
    $errors[] = 'All bees dead after run';
} else {
    echo "OK ({$alive} alive of " . count($bees) . ")\n";
}

// ── RESULT ─────────────────────────────────────────────
echo "\n" . str_repeat('=', 50) . "\n";
if (empty($errors)) {
    echo "SMOKE TEST: PASS ✓ (mode: " . ($full ? 'full' : 'quick') . ", {$ticks} ticks)\n";
    exit(0);
} else {
    echo "SMOKE TEST: FAIL ✗\n";
    foreach ($errors as $e) {
        echo "  • {$e}\n";
    }
    exit(1);
}
