<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\ContradictionDetector;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 RED: ContradictionDetector (§2.5.3 диссипативного контура).
 *
 * Критерий протокола: две пчелы нашли РАЗНЫЕ формулы для одной задачи,
 * обе CV ≤ 0.01 (exact-класс) → contradiction-событие + D_diff расчёт
 * (подмножество, где |f_A(x) − f_B(x)| > δ).
 *
 * Story: EvoFamily/ProductSpecification/stories/dissipation-loop/progress.md
 */
final class ContradictionDetectorTest extends TestCase
{
    private const EPS_EXACT = 0.01;
    private const DELTA_DIFF = 0.05;

    /** @var array<int,array<int,float>> synthetic task rows: [x0, x1, y] (числовые индексы — контракт ExpressionEvaluator) */
    private array $task = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Задача: y = x0 × x1 (выразима базовой грамматикой). rows = [f0, f1, y]
        for ($x0 = 1; $x0 <= 12; $x0++) {
            $x1 = $x0 + 1;
            $this->task[] = [(float) $x0, (float) $x1, (float) ($x0 * $x1)];
        }
    }

    public function testClassExists(): void
    {
        self::assertTrue(class_exists(ContradictionDetector::class));
    }

    public function testDetectReturnsNullWhenOnlyOneCandidate(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        $cands = [$this->candidate('f_a', 0.005)];
        self::assertNull($det->detect($this->task, $cands));
    }

    public function testDetectReturnsNullWhenBothApproximate(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        $cands = [
            $this->candidate('f_a', 0.15),
            $this->candidate('f_b', 0.12),
        ];
        self::assertNull($det->detect($this->task, $cands), 'CV > exact-порога → не противоречие');
    }

    public function testDetectReturnsNullWhenFormulasIdentical(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        $cands = [
            $this->candidate('(x0×x1)', 0.0),
            $this->candidate('(x1×x0)', 0.0), // коммутативный двойник — одна формула
        ];
        self::assertNull($det->detect($this->task, $cands), 'одинаковые формулы → нет противоречия');
    }

    public function testDetectReturnsEventWhenTwoExactButDifferent(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        $cands = [
            $this->candidate('(x0×x1)', 0.0),      // истина
            $this->candidate('(x0+x1+2×x0×x1/(x0+x1+0.001))', 0.005), // другая, тоже exact-класс на этом ряде
        ];
        $event = $det->detect($this->task, $cands);
        self::assertNotNull($event, 'две exact-формулы, структурно разные → противоречие');
        self::assertArrayHasKey('diff_rows', $event);
        self::assertNotEmpty($event['diff_rows'], 'D_diff непуст: формулы дают разные значения');
        self::assertCount(count($cands), $event['candidates']);
    }

    public function testDiffRowsExcludeSmallDivergence(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        // Формулы совпадают везде (разница < δ) → D_diff пуст → null
        $cands = [
            $this->candidate('(x0×x1)', 0.0),
            $this->candidate('(x1×x0)', 0.0),
        ];
        // через одинаковые формулы уже покрыто; здесь проверяем δ-границу на почти-равных
        $near = [
            $this->candidate('(x0×x1)', 0.0),
            $this->candidate('(x0×x1+0.001)', 0.0),
        ];
        $event = $det->detect($this->task, $near);
        self::assertNull($event, 'дивергенция < δ → не противоречие');
    }

    public function testUnevaluableFormulaIsSkippedNotContaminating(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        // (x0/x1) вычислима; (x0/(x1−x1)) = деление на ноль → evaluator null/INF → пропуск
        $cands = [
            $this->candidate('(x0×x1)', 0.0),
            $this->candidate('(x0/(x1−x1))', 0.005),
        ];
        $event = $det->detect($this->task, $cands);
        // пара пропущена (невычислимая), других пар нет → противоречия нет
        self::assertNull($event, 'невычислимая формула не создаёт ложное/отравленное событие');
    }

    public function testCandidatesCarryFormulaAndCv(): void
    {
        $det = new ContradictionDetector(self::EPS_EXACT, self::DELTA_DIFF);
        $c = $this->candidate('(x0×x1)', 0.0);
        $event = $det->detect($this->task, [$c, $this->candidate('(x0×x1+0.5×x0)', 0.009)]);
        self::assertNotNull($event);
        foreach ($event['candidates'] as $cand) {
            self::assertArrayHasKey('formula', $cand);
            self::assertArrayHasKey('cv', $cand);
            self::assertLessThanOrEqual(self::EPS_EXACT, $cand['cv']);
        }
    }

    /** @return array{formula: string, cv: float} */
    private function candidate(string $formula, float $cv): array
    {
        return ['formula' => $formula, 'cv' => $cv];
    }
}
