<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;

/**
 * EXP-063 prereg: TRAIL_READ_DIVERSITY=1 — the cluster-based retrieval
 * (the column-coverage greedy + the corr fill) extracted into
 * Search::selectReadDiverse.
 *
 * The E62 forensics: the read top-5 (uses/corr) = captured by the
 * contamination family (all {c0,c5}-column marks) — the useful mark
 * (c3×c4) = written, never read (the coverage-starved). The fix = the
 * retrieval diversity: the pass 1 = the greedy over the marks whose
 * touched-column set is disjoint from the already-selected (the cluster
 * diversity), the pass 2 = the corr fill to K.
 *
 * The flag: TRAIL_READ_DIVERSITY=1 (the default off = the corr order).
 */
final class TrailReadDiversityTest extends \PHPUnit\Framework\TestCase
{
    /**
     * The candR-shaped rows (the post-eval candidates): {name, v, corr, stair}.
     * The family = the {c0,c5}-column marks (the contamination class, the
     * corr higher); the lone = the {c3,c4} mark (the useful, the corr lower).
     *
     * @return list<array<string, mixed>>
     */
    private static function candRows(): array
    {
        return [
            ['name' => '(c0+c5)', 'v' => null, 'corr' => 0.50, 'stair' => true],
            ['name' => '(c0×c5)', 'v' => null, 'corr' => 0.48, 'stair' => false],
            ['name' => '(c0+c0)', 'v' => null, 'corr' => 0.46, 'stair' => true],
            ['name' => '(c5+c5)', 'v' => null, 'corr' => 0.44, 'stair' => true],
            ['name' => '(c0−c5)', 'v' => null, 'corr' => 0.42, 'stair' => false],
            ['name' => '(c0+c2)', 'v' => null, 'corr' => 0.40, 'stair' => true],
            ['name' => '(c3×c4)', 'v' => null, 'corr' => 0.35, 'stair' => false],
        ];
    }

    public function testDiversitySelectsDisjointColumnMarkFirst(): void
    {
        putenv('TRAIL_READ_DIVERSITY=1');
        $sel = Search::selectReadDiverse(self::candRows(), 5);
        $names = array_column($sel, 'name');
        $this->assertContains(
            '(c3×c4)',
            $names,
            'the diversity retrieval must surface the disjoint-column mark over the entrenched family'
        );
        // The diversity contract: the first pass = the column-disjoint marks
        // (each new mark touches a NEW column pair) — the (c0+c5) = the first
        // by corr, the (c3×c4) = the second (the disjoint {c3,c4}).
        $this->assertSame('(c0+c5)', $names[0], 'the corr-leader starts the coverage');
        $this->assertSame('(c3×c4)', $names[1], 'the disjoint mark = the second pick (the cluster diversity)');
    }

    /**
     * The default-mode (no flag) = the wiring question, verified by the
     * control cell of the EXP-063 map (the method = the pure function,
     * always diverse; the legacy order = the call-site's else-branch).
     */
    public function testDiversityFillPhaseCompletesToK(): void
    {
        // The pass 2 = the corr fill: K=5 reached even when the disjoint
        // marks are fewer than K (the coverage-first, the fill-second).
        $sel = Search::selectReadDiverse(self::candRows(), 5);
        $this->assertCount(5, $sel, 'the fill phase must complete the selection to K');
        $names = array_column($sel, 'name');
        $this->assertSame('(c0+c5)', $names[0], 'the corr-leader starts');
        $this->assertSame('(c3×c4)', $names[1], 'the disjoint mark = the second');
    }
}
