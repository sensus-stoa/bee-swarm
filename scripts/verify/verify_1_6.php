#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * verify_1_6.php — Environmental Pressure (§2.6)
 *
 * Проверяет на логе демона (agenda.log):
 * (a) ≥1 задача выброшена TIMEOUT (MISSED_OPPORTUNITY),
 * (b) сложность задач менялась ≥1 раз (ENV_DIFF),
 * (c) carrying capacity: N_max/N_median ≤ 3 по GEN pop= (экстинкции N=0
 *     исключены; >3 — FAIL: взрывной рост/шумовая нестабильность).
 *
 * Pass: все три условия.
 *
 * Usage: php verify_1_6.php <agenda.log>
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Hive\EnvPressureVerify;

$logFile = $argv[1] ?? null;
if (! $logFile || ! file_exists($logFile)) {
    echo "SKIP: No log file\nUsage: php verify_1_6.php <agenda.log>\n";
    exit(0);
}

$content = (string) file_get_contents($logFile);
$r = EnvPressureVerify::run($content);

echo "MISSED_OPPORTUNITY events: {$r['missed']}\n";
echo "Difficulty changes (ENV_DIFF): {$r['diffChanges']}\n";
echo 'GEN observations: ' . $r['generations'] . "\n";
echo 'Carrying capacity: max=' . $r['cc']['max']
    . ' median=' . round((float) $r['cc']['median'], 2)
    . ' ratio=' . round((float) $r['cc']['ratio'], 3)
    . ' excluded_extinctions=' . $r['cc']['excluded']
    . ($r['cc']['inconclusive'] ? ' (INCONCLUSIVE)' : '') . "\n";

foreach ($r['pending'] as $p) {
    echo "PENDING: {$p}\n";
}

if ($r['pass']) {
    echo "PASS: §2.6 environmental pressure criteria met\n";
    exit(0);
}

echo "FAIL: §2.6 criteria not met\n";
exit(1);
