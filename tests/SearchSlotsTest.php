<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * A2 (pysr-rematch): каскад слотов в Search — SUM-каркасы из ×-пар.
 *
 * EXP-036: dot 0/20 — beam по CV убивает частичные суммы (cv≈2.7),
 * закон проявляется ТОЛЬКО на финальном узле. Слоты: тройки ×-форм
 * L1 собираются в каркас БЕЗ beam; критерий сборки — cv→0 факта
 * (тот же гейт cvTrainMax, что у всех форм). Прототип:
 * Benchmarks/exp039_slots_fastcheck.php — PASS 0.01s, FPR=0/1365.
 *
 * Гварды честности: shuffle-y не собирается (FPR=0), depth-2 гейт —
 * каскад не запускается на мелкой глубине.
 */
class SearchSlotsTest extends TestCase
{
    /**
     * y = x0·x3 + x1·x4 + x2·x5 — точный dot-класс, 6 фич, детерминизм по seed.
     *
     * @return array{0: array<array<float>>, 1: array<float>}
     */
    private function dotData(int $rows = 40, int $seed = 7): array
    {
        mt_srand($seed);
        $X = [];
        $y = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [
                (float) mt_rand(-50, 50) / 10.0,
                (float) mt_rand(-50, 50) / 10.0,
                (float) mt_rand(-50, 50) / 10.0,
                (float) mt_rand(1, 90) / 10.0,
                (float) mt_rand(1, 90) / 10.0,
                (float) mt_rand(1, 90) / 10.0,
            ];
            $X[] = $row;
            $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
        }

        return [$X, $y];
    }

    private function grammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq']);

        return $g;
    }

    public function testDotSolvedBySlotCascadeInEngine(): void
    {
        // Критерий A2: dot решается В ДВИЖКЕ без beam-эскалации.
        [$X, $y] = $this->dotData();

        [$found, $cv, $formula] = Search::find($X, $y, $this->grammar(), 3, null, 0.0, 0.15, 30.0);

        $this->assertTrue($found, 'dot-class must be found by slot cascade');
        $this->assertSame(0.0, $cv, 'slot law must be exact');
        // Каркас = тройка ×-слагаемых (порядок пар канонический по индексам)
        $this->assertStringContainsString('(x0×x3)', (string) $formula, "got: {$formula}");
        $this->assertStringContainsString('(x1×x4)', (string) $formula, "got: {$formula}");
        $this->assertStringContainsString('(x2×x5)', (string) $formula, "got: {$formula}");
    }

    public function testShuffledYRefusesSlotAssembly(): void
    {
        // FPR-гвард (прототип: 0/1365 на shuffle): перемешанный y не собирается.
        [$X, $y] = $this->dotData();
        mt_srand(99);
        shuffle($y);

        [$found] = Search::find($X, $y, $this->grammar(), 3, null, 0.0, 0.15, 30.0);

        $this->assertFalse($found, 'shuffled y must not assemble a slot law');
    }

    public function testSlotCascadeGatedByDepth(): void
    {
        // depth=2: каскад не запускается — поведение не меняется для
        // существующих задач малой глубины (dot на depth-2 невыразим).
        [$X, $y] = $this->dotData();

        [$found, $cv, $formula] = Search::find($X, $y, $this->grammar(), 2, null, 0.0, 0.15, 10.0);

        $this->assertFalse($found, 'depth-2 must keep pre-A2 behavior on dot data');
    }
}
