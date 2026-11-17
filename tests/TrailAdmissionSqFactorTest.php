<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

/**
 * EXP-062 prereg: TRAIL_ADMIT_SQ_FACTOR — the AST-level sq-admissibility.
 *
 * Owner decision (11.11, layer 2 of the E60/E61 mechanism): the blunt
 * clean-signal filter (ANY ²/sq/min/max/√ = junk) = the anti-composite
 * gate — it excludes the composite-shaped forms (c5+(c0×c2²)) before any
 * ranking. The fix = the AST distinction: a sq node is admissible iff it
 * is sq(atom) as a DIRECT factor of a × node; min/max/√ and any non-base
 * op stay banned; the parse failure = not admissible.
 *
 * The flag: TRAIL_ADMIT_SQ_FACTOR=1 (the default off = the blunt filter).
 * The filter lives at the plausibleForMarks construction (the call site),
 * NOT inside writeTrailMarks — the write path = filter-agnostic (the
 * trust-the-input contract, tested here), the flag test = the helper unit.
 */
final class TrailAdmissionSqFactorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec('DELETE FROM trail_marks');
        putenv('TRAIL_ADMISSION_MODE');
    }

    protected function tearDown(): void
    {
        putenv('TRAIL_ADMIT_SQ_FACTOR');
        putenv('TRAIL_ADMISSION_MODE');
        parent::tearDown();
    }

    /**
     * The helper truth-table (the RED: the method does not exist yet).
     * The admissible ops = {+, −, ×, /, sq, the atoms}; the sq = only
     * sq(atom) under a × parent; everything else (max/min/√/the unknown
     * local ops) = banned; the parse failure = not admissible.
     */
    public function testSqFactorAdmissibleTruthTable(): void
    {
        $m = new \ReflectionMethod(Search::class, 'sqFactorAdmissible');
        $m->setAccessible(true);
        $cases = [
            '(c5+(c0×c2²))' => true,   // the E60 partial: the sq(atom) under ×
            '(c0×c2²)' => true,        // the bare product with the squared factor
            '(c2²/c4)' => false,       // the sq under / = the 30.10 wrapper junk
            '((c0+c5)−(c0maxc2)²)' => false, // the sq over a structure + the max op
            '(c0maxc2)' => false,      // the local op = banned by the whitelist
            'c2²' => false,            // the bare sq: no × parent = the wrapper class
            '(c0+c5)' => true,         // no sq at all: the base grammar = fine
        ];
        foreach ($cases as $form => $expected) {
            $this->assertSame($expected, $m->invoke(null, $form), "sqFactorAdmissible($form)");
        }
    }

    /**
     * The write path = filter-agnostic: writeTrailMarks writes what it
     * receives (the filter = the call-site responsibility). The regression
     * guard: the filter must NOT migrate into the write path.
     */
    public function testWritePathIsFilterAgnostic(): void
    {
        [$X, $y] = self::task();
        $plausible = [
            ['name' => '(c5+(c0×c2²))', 'cv' => 0.15, 'corr' => 0.35],
        ];
        $labels = [];
        foreach (range(0, 5) as $j) {
            $labels[$j] = 'c' . $j;
        }
        $m = new \ReflectionMethod(Search::class, 'writeTrailMarks');
        $m->setAccessible(true);
        $m->invoke(null, $labels, $plausible, 9.99, INF, [], [], $X, $y);
        $this->assertContains('(c5+(c0×c2²))', Search::$lastTrailWritten ?? [],
            'the write path must stay filter-agnostic: it writes what the caller constructed');
    }

    /**
     * The composition: the filter admits the partial, the cv-argmin writes
     * it FIRST (the two flags together = the full layer-1+2 mechanism).
     */
    public function testCvArgminWritesCompositePartialFirst(): void
    {
        putenv('TRAIL_ADMIT_SQ_FACTOR=1');
        putenv('TRAIL_ADMISSION_MODE=cv_argmin');
        [$X, $y] = self::task();
        $plausible = [
            ['name' => '(c0+c5)', 'cv' => 0.30, 'corr' => 0.40],       // the contamination, the worst cv
            ['name' => '(c2²/c4)', 'cv' => 0.20, 'corr' => 0.55],      // the junk: the filter must exclude it
            ['name' => '(c5+(c0×c2²))', 'cv' => 0.15, 'corr' => 0.35], // the partial: the best cv
        ];
        $labels = [];
        foreach (range(0, 5) as $j) {
            $labels[$j] = 'c' . $j;
        }
        $m = new \ReflectionMethod(Search::class, 'writeTrailMarks');
        $m->setAccessible(true);
        $m->invoke(null, $labels, $plausible, 9.99, INF, [], [], $X, $y);
        $written = Search::$lastTrailWritten ?? [];
        $this->assertSame('(c5+(c0×c2²))', $written[0] ?? null,
            'the cv-argmin must write the composite partial first (the rank = the cv)');
        // NB: the junk ((c2²/c4)) IS written here — the write path is
        // filter-agnostic (the trust-the-input contract, tested above);
        // the junk exclusion = the call-site filter (the E62 map scope).
        $this->assertContains('(c0+c5)', $written, 'the contamination = admissible (the filter = shape-only)');
    }

    /**
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function task(int $nRows = 40): array
    {
        mt_srand(23);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = -2.0 + mt_rand() / mt_getrandmax() * 4.0;
            }
            $X[] = $row;
            $y[] = $row[0] + $row[5] + 0.1 * ($row[3] * $row[4]);
        }

        return [$X, $y];
    }
}
