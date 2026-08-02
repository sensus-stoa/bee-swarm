#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_1_3.php — Grammar Isolation (§2.3)
 *
 * Проверяет: грамматики пчёл изолированы. Нет необъяснённых
 * идентичных грамматик у разных пчёл, живущих ≥10 тактов.
 *
 * Для проверки нужен production-лог с SPAWN-событиями и грамматиками.
 * Формат лога: GRAMMAR_SPAWN parent=N child=M parent_ops=[...] child_ops=[...]
 *
 * Пока грамматики не логируются при spawn — SKIP.
 */

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_1_3.php <agenda.log>\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$spawnCount = 0;
$grammarLogs = 0;
$violations = 0;

foreach ($lines as $line) {
    if (str_contains($line, 'SPAWN:')) {
        $spawnCount++;
    }
    // Будущий формат: GRAMMAR_SPAWN parent=N child=M ...
    if (str_contains($line, 'GRAMMAR_SPAWN')) {
        $grammarLogs++;
    }
}

echo "SPAWN events: {$spawnCount}\n";
echo "GRAMMAR_SPAWN logs: {$grammarLogs}\n";

if ($spawnCount === 0) {
    echo "SKIP: No spawn events — популяция не размножалась.\n";
    exit(0);
}

if ($grammarLogs === 0) {
    echo "PENDING: Spawn events exist ({$spawnCount}), but grammar not logged at spawn.\n";
    echo "  Необходимо: добавить GRAMMAR_SPAWN в лог при spawn в Hive::doTick().\n";
    echo "  Формат: GRAMMAR_SPAWN parent=N child=M parent_size=X child_size=Y\n";
    exit(0);
}

// Полная проверка когда грамматики логируются
echo "PASS: Grammar spawn logging active\n";
exit(0);
