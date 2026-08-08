<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Infra\PlateauDetector;

/**
 * LIFETIME-METRIC (P1, 07.08): Median Colony Lifetime — агрегированный
 * фитнес. DEATH логирует lifespan (tick смерти − tick рождения),
 * GEN логирует avg_lifetime.
 */
class LifetimeMetricTest extends TestCase
{
    public function testDeathLogsLifespan(): void
    {
        // NO_NOVELTY=1: детерминизм (NOVELTY +0.5×N типов не кормит пчелу)
        putenv('NO_NOVELTY=1');
        $logFile = tempnam(sys_get_temp_dir(), 'lm_');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 30,
            logFile: $logFile,
        );

        // Пчела с малым запасом энергии умрёт на раннем тике
        $ref = new \ReflectionClass(Hive::class);
        $bees = $ref->getProperty('bees');
        $bees->setAccessible(true);
        $bees->setValue($hive, [new Bee(['+'], 0.0005)]); // смерть на 1-м тике (тик 0.001, до NOVELTY)

        $hive->run();
        putenv('NO_NOVELTY');

        $log = file_get_contents($logFile);
        $deathLines = [];
        foreach (explode("\n", $log) as $line) {
            if (str_contains($line, 'DEATH:')) {
                $deathLines[] = $line;
            }
        }

        $this->assertNotEmpty($deathLines, 'bee must die');
        $this->assertMatchesRegularExpression(
            '/DEATH:.*life=\d+/',
            $deathLines[0],
            'DEATH log must include lifespan: ' . $deathLines[0]
        );
    }

    public function testGenerationLogsAverageLifetime(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'lm2_');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 15,
            logFile: $logFile,
        );

        $ref = new \ReflectionClass(Hive::class);
        $bees = $ref->getProperty('bees');
        $bees->setAccessible(true);
        $bees->setValue($hive, [new Bee(['+'], 20.0)]); // выживет, спавнится → GEN

        $hive->run();

        $log = file_get_contents($logFile);
        $this->assertMatchesRegularExpression(
            '/GEN:.*avg_lifetime=\d+/',
            $log,
            'GEN log must include avg_lifetime'
        );
    }
}
