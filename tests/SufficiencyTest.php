<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Story 04: Statistical Sufficiency (HONEST_CRITERIA §1.2)
 *
 * @group disabled
 */
class SufficiencyTest extends TestCase
{
    /** t < t_min → пустой результат */
    public function test_insufficient_data_returns_empty(): void
    {
        // 3 точки — явно недостаточно (t_min = max(10, 1*5) = 10 для unary)
        $X = [[1.0], [2.0], [3.0]];
        $y = [1.0, 2.0, 3.0];

        $result = AtomRegistry::discover($X, $y);

        $this->assertIsArray($result);
        $this->assertEmpty($result,
            'discover() must return empty when t < t_min to prevent false positives');
    }

    /** discoverHeldout тоже требует t_min */
    public function test_heldout_requires_sufficiency(): void
    {
        $X = [[1.0], [2.0], [3.0]];
        $y = [1.0, 2.0, 3.0];

        $result = AtomRegistry::discoverHeldout($X, $y);

        $this->assertEmpty($result,
            'discoverHeldout() must return empty when insufficient data');
    }

    /** t ≥ t_min → нормальный поиск */
    public function test_sufficient_data_works(): void
    {
        // 12 точек > t_min=10
        $X = [[1.0],[2.0],[3.0],[4.0],[5.0],[6.0],[7.0],[8.0],[9.0],[10.0],[11.0],[12.0]];
        $y = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 11.0, 12.0];

        $result = AtomRegistry::discover($X, $y);

        // x0 должен найтись (identity)
        $atoms = array_column($result, 'atom');
        $this->assertContains('x0', $atoms,
            'discover() must work when t ≥ t_min');
    }
}
