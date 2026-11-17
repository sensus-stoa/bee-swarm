<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;

/**
 * EXP-064 prereg: TRAIL_WRITE_DIVERSITY=1 — the write-side cluster diversity
 * (the greedy column-coverage reorder before the admission cap-walk).
 *
 * The forensics (partial road, seed 403): the honest lone product (c3×c4)
 * = 32/32 EVAL_OK in rankTrailCandidates, but 0 times written — the proxy-desc
 * cap-walk fills all 5 slots with the contamination family (the partial
 * composite + its cancellation wrappers (A−B)+B, proxy ~0.94 vs the honest
 * 0.459; y is dominated by c0·c2² and c5). The walk breaks at 5 admitted
 * before reaching the lone term.
 *
 * The fix contract: pass 1 = the greedy over the marks whose touched-column
 * set is DISJOINT from the already-selected (the cluster diversity — the
 * representatives of the different niches go to the head of the queue),
 * pass 2 = the rest by proxy. The caps/ghost rules untouched — the ORDER
 * is the only change. The flag TRAIL_WRITE_DIVERSITY=1 gates it (the
 * default off = the byte-identical proxy order).
 */
final class TrailWriteDiversityTest extends \PHPUnit\Framework\TestCase
{
    /**
     * The ranked-shaped rows (the post-rank candidates): {name, proxy}.
     * The family = the {c0,c2,c5}-column marks (the contamination class,
     * the proxy higher); the lone = the {c3,c4} mark (the honest, lower).
     *
     * @return list<array<string, mixed>>
     */
    private static function rankedRows(): array
    {
        return [
            ['name' => '(c5+(c0×c2²))', 'proxy' => 0.945],
            ['name' => '(c0+c5)', 'proxy' => 0.725],
            ['name' => '(c0×c1)', 'proxy' => 0.117],
            ['name' => '(c3×c4)', 'proxy' => 0.459],
        ];
    }

    public function testDiversityPutsDisjointColumnMarkInHead(): void
    {
        putenv('TRAIL_WRITE_DIVERSITY=1');
        $ordered = Search::orderWriteDiverse(self::rankedRows());
        $names = array_column($ordered, 'name');
        $this->assertSame(
            '(c5+(c0×c2²))',
            $names[0],
            'the proxy-leader starts the coverage (the greedy keeps the order inside the pass)'
        );
        $this->assertSame(
            '(c3×c4)',
            $names[1],
            'the disjoint-column mark = the second pick (the cluster diversity) — despite the lower proxy'
        );
    }

    public function testOverlappingFamilyPushedToRest(): void
    {
        putenv('TRAIL_WRITE_DIVERSITY=1');
        $ordered = Search::orderWriteDiverse(self::rankedRows());
        $names = array_column($ordered, 'name');
        // The family members (the overlapping columns {0,2,5}/{0,5}/{0,1})
        // = the pass-2 rest, the proxy order preserved among them.
        $restIdx = array_search('(c0+c5)', $names, true);
        $this->assertTrue($restIdx !== false, 'the overlapping family member is still present (nothing dropped)');
        $this->assertTrue(
            array_search('(c0+c5)', $names, true) < array_search('(c0×c1)', $names, true),
            'the rest preserves the proxy order: (c0+c5) 0.725 was ahead of (c0×c1) 0.117 in the input'
        );
        $this->assertTrue($restIdx > 1, 'the family member goes AFTER the diversity head');
    }

    public function testDefaultOffKeepsProxyOrderByteIdentical(): void
    {
        putenv('TRAIL_WRITE_DIVERSITY');
        $rows = self::rankedRows();
        $this->assertSame(
            array_column($rows, 'name'),
            array_column(Search::orderWriteDiverse($rows), 'name'),
            'the flag off = the identity (the proxy order untouched)'
        );
    }

    public function testNoColumnFormsGoToRest(): void
    {
        putenv('TRAIL_WRITE_DIVERSITY=1');
        $rows = [
            ['name' => '(c0×c5)', 'proxy' => 0.5],
            ['name' => 'RKconst', 'proxy' => 0.4], // no c/x columns
            ['name' => '(c3×c4)', 'proxy' => 0.2],
        ];
        $names = array_column(Search::orderWriteDiverse($rows), 'name');
        $this->assertSame('(c0×c5)', $names[0], 'the first pick opens the coverage');
        $this->assertSame('(c3×c4)', $names[1], 'the disjoint mark follows');
        $this->assertSame('RKconst', $names[2], 'the no-column form = the pass-2 rest (never blocks the coverage)');
    }
}
