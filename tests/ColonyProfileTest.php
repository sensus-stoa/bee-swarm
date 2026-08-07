<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * ЭКСП-018: Colony Economics Profile.
 * PROFILE=1 → лог с таймингами: TICK_MS, SEARCH_MS, WAIT (нет задач),
 * энергетический баланс по классам |G| в GEN.
 */
class ColonyProfileTest extends TestCase
{
    public function testProfileLogsTickAndSearchTiming(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'prof_');
        putenv('PROFILE=1');
        try {
            $hive = new Hive(
                plateau: new PlateauDetector(50, plateauSleepUs: 0),
                maxTicks: 5,
                logFile: $logFile,
            );
            $hive->run();
        } finally {
            putenv('PROFILE');
        }

        $log = file_get_contents($logFile);
        $this->assertMatchesRegularExpression(
            '/TICK_MS=\d+/',
            $log,
            'PROFILE=1 must log TICK_MS'
        );
        $this->assertMatchesRegularExpression(
            '/SEARCH_MS=\d+/',
            $log,
            'PROFILE=1 must log SEARCH_MS'
        );
    }

    public function testProfileLogsEnergyBalanceByGrammarSize(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'prof2_');
        putenv('PROFILE=1');
        try {
            $hive = new Hive(
                plateau: new PlateauDetector(50, plateauSleepUs: 0),
                maxTicks: 5,
                logFile: $logFile,
            );
            $hive->run();
        } finally {
            putenv('PROFILE');
        }

        $log = file_get_contents($logFile);
        $this->assertMatchesRegularExpression(
            '/G_BALANCE:/',
            $log,
            'PROFILE=1 must log energy balance by |G| classes'
        );
    }
}
