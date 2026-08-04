<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Story S1.2 Phase 4: Gap-Triggered Spawn
 *
 * Когда пчёлы не могут набрать E≥15 для обычного спавна,
 * gap-spawn разрешает размножение при долгом PLATEAU.
 */
class GapSpawnTest extends TestCase
{
    /**
     * RED: PLATEAU > 5×P тактов → GAP_SPAWN.
     *
     * Используем PlateauDetector с порогом 3 для быстрого теста.
     *
     * Predicted: count(GAP_SPAWN)=0 — gap-spawn не реализован.
     */
    public function testGapSpawnOnPlateau(): void
    {
        $plateau = new PlateauDetector(2, plateauSleepUs: 1_000); // быстрый сон для теста
        $logFile = tempnam(sys_get_temp_dir(), 'gapspawn_');
        $hive = new Hive(plateau: $plateau, maxTicks: 25, logFile: $logFile);

        $hive->run();

        // Проверяем логи
        $log = file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('GAP_SPAWN', $log,
            'PLATEAU > 15 тактов (5×P=3) must trigger GAP_SPAWN');
    }

    /**
     * RED: E=0 → нет gap-spawn (мёртвая пчела не размножается).
     */
    public function testNoGapSpawnWhenDead(): void
    {
        $plateau = new PlateauDetector(2, plateauSleepUs: 1_000);
        $logFile = tempnam(sys_get_temp_dir(), 'gapspawn_dead_');
        $hive = new Hive(plateau: $plateau, maxTicks: 25, logFile: $logFile);

        // Запускаем — пчёлы умрут от голода на plateau
        // GAP_SPAWN не должно быть после смерти всех пчёл
        $hive->run();

        $log = file_get_contents($logFile);
        unlink($logFile);

        // Если все пчёлы мертвы, GAP_SPAWN не должен появляться ПОСЛЕ последней DEATH
        $lines = explode("\n", $log);
        $lastDeath = 0;
        $gapSpawnAfterDeath = false;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'DEATH:')) {
                $lastDeath = $i;
            }
            if (str_contains($line, 'GAP_SPAWN') && $i > $lastDeath && $lastDeath > 0) {
                $gapSpawnAfterDeath = true;
            }
        }

        $this->assertFalse($gapSpawnAfterDeath,
            'GAP_SPAWN must not occur when all bees are dead');
    }
}
