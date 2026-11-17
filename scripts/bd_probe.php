<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=');
mt_srand(42);

$hive = new BeeSwarm\Hive\Hive(
    plateau: new BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0),
    maxTicks: 20,
    logFile: '/tmp/bd_probe.log',
);
$hive->run();
echo "PROBE DONE ticks=20\n";
