<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Framework\TestCase;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

/**
 * MATERIAL QUALITY (30.10, PREREG_MATERIAL_PROBE.md): производство дороги.
 *
 * Проба (probe_loop dot 101): write-on v1.1 льёт на дорогу 94% мусора
 * (min/max-гибриды signal-коллектора) → лестница 0/8 found (ядовитая
 * кормушка: m3=78 reuses мусора). Hand-seed ТОЧНЫХ ступеней → 8/8 found.
 * Гейты (STRUCT/CORR_MIN) дают чистоту без наполнения (written=1, 0/8) —
 * фильтром симптом не лечить (bio-порядок: производство → admission).
 *
 * Контракт производства: на дорогу попадают ТОЛЬКО чистые структуры —
 * stair (2-term композиции), petal (голые продукты), bestName найденного
 * закона. signal-гибриды (min/max/sq-обёртки) — компас вылета (WU-3),
 * НЕ дорожный материал. И дорога не пуста (наполнение — V4-контракт §1).
 */
final class TrailMaterialQualityTest extends TestCase
{
    private const LABELS = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];

    protected function setUp(): void
    {
        // Гигиена полная (pitfall 41): пин НУЖНЫХ значений явно, сосед-unset не страшен.
        putenv('SWARM_DB_PATH=:memory:');
        parent::setUp();
        Database::get()->exec('DELETE FROM trail_marks');
        Database::get()->exec('DELETE FROM trail_ghosts');
        putenv('TRAIL_MARKS=1');
        putenv('TRAIL_READ_K');
        putenv('TRAIL_FIX');
        putenv('TRAIL_SCOPE');
        putenv('TRAIL_ADMIT_CORR_MIN');
        putenv('TRAIL_ADMIT_STRUCT');
        putenv('SEARCH_RAWPAIR_POOL=1');
        putenv('SEARCH_BEAM_K=10');
        putenv('SEARCH_NO_PREREG=1');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
        Search::$lastPoolFixAppended = [];
    }

    protected function tearDown(): void
    {
        Database::get()->exec('DELETE FROM trail_marks');
        Database::get()->exec('DELETE FROM trail_ghosts');
        parent::tearDown();
    }

    /** DOT-закон (probe_loop dot_task): y = x0·x3 + x1·x4 + x2·x5, 6 колонок. */
    private function dotTask(): array
    {
        mt_srand(4242);
        $u = fn (float $a, float $b): float => $a + mt_rand() / mt_getrandmax() * ($b - $a);
        $X = [];
        $y = [];
        for ($i = 0; $i < 200; $i++) {
            $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
            $X[] = $row;
            $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
        }
        return [$X, $y];
    }

    /** Лестничный геном (канон probe_loop ladder): 3 FLIGHT-прохода. */
    private function ladderGenome(int $seq): array
    {
        $g = ['id' => "hs_{$seq}", 'budget_fraction' => 1.0, 'actions' => []];
        for ($f = 0; $f < 3; $f++) {
            $g['actions'][] = ['op' => 'FLIGHT', 'depth' => 2, 'beam' => 'default'];
        }
        return $g;
    }

    private function writtenFormulas(): array
    {
        return array_column(
            Database::get()->query('SELECT formula FROM trail_marks')->fetchAll(\PDO::FETCH_ASSOC),
            'formula'
        );
    }

    public function testRoadMaterialIsCleanStructuresNotHybridJunk(): void
    {
        [$X, $y] = $this->dotTask();
        $g = Grammar::fromOps(array_keys(Grammar::BASE_OPS));

        foreach ([0, 1, 2] as $seq) {
            Search::find($X, $y, $g, 2, self::LABELS, 0.0, 0.15, 5.0);
        }

        $written = $this->writtenFormulas();
        $this->assertNotEmpty($written, 'дорога пуста — производство не даёт материал (FAIL-производство)');

        foreach ($written as $wf) {
            $isClean = preg_match('/min|max|sq|²|√/u', $wf) !== 1;
            $this->assertTrue($isClean, "гибрид-мусор на дороге: {$wf}");
        }
    }

    public function testRoadFillsWithPureTwoTermStructures(): void
    {
        [$X, $y] = $this->dotTask();
        $g = Grammar::fromOps(array_keys(Grammar::BASE_OPS));

        foreach ([0, 1, 2] as $seq) {
            Search::find($X, $y, $g, 2, self::LABELS, 0.0, 0.15, 5.0);
        }

        $written = $this->writtenFormulas();
        // Уточнение после проб 30.10 (документировано в progress.md):
        // материал лестницы mark⊕parent = ступени (2-term суммы) И голые
        // произведения raw-фич (hand-seed с ними дал 8/8). Патология gated-руки
        // — written=1 ЦЕЛИКОМ, а не отсутствие 2-term сумм как класса.
        $usable = array_filter($written, fn (string $f): bool =>
            preg_match('/min|max|sq|²|√/u', $f) !== 1);
        $this->assertGreaterThanOrEqual(
            3,
            count($usable),
            'дорога не наполняется чистым материалом: ' . implode('; ', array_slice($written, 0, 8))
        );
    }
}
