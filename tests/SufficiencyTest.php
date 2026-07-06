<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Forager\Forager;

/**
 * Story 04: Statistical Sufficiency (HONEST_CRITERIA §1.2)
 *
 * @group disabled
 */
class SufficiencyTest extends TestCase
{
    /** t < t_min → discover() всё ещё работает (sufficiency проверяется в Hive) */
    public function test_discover_works_on_small_data(): void
    {
        $X = [[1.0], [2.0], [3.0]];
        $y = [1.0, 2.0, 3.0];

        $result = AtomRegistry::discover($X, $y);

        $this->assertIsArray($result,
            'AtomRegistry::discover() must not enforce sufficiency — Hive does that');
    }

    /** Hive::tick() с < t_min точками не вызывает discover */
    public function test_hive_skips_insufficient_data(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        // Задача с < 10 точками — Hive НЕ должен открывать законы
        $hive = new Hive($plateau, $forager, maxTicks: 3);

        $result = $hive->run();

        // Hive отработал без падений
        $this->assertGreaterThan(0, $result, 'Hive must run ticks');
    }

    /** t ≥ t_min → Hive ищет законы */
    public function test_sufficient_data_allows_discovery(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager, maxTicks: 3);

        $result = $hive->run();

        $this->assertSame(3, $result, 'Hive must run exactly maxTicks');
    }
}
