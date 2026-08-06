<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;

/**
 * Контракт: Grammar::apply и AtomRegistry::apply идентичны.
 */
class EvaluatorContractTest extends TestCase
{
    public function testGrammarAndAtomRegistryAgreeUnary(): void
    {
        $grammar = new Grammar();
        $ops = array_values(array_filter(
            $grammar->getUnaryOps(),
            fn (string $op) => ! str_starts_with($op, 'B') // born-атомы — в GrammarBirth*
        ));
        $testValues = [-10.0, -1.0, 0.0, 1.0, 10.0, 3.14, -0.5, 2.0];

        $mismatches = [];
        foreach ($ops as $op) {
            foreach ($testValues as $a) {
                $gResult = $grammar->apply($a, 0.0, $op);
                $aResult = AtomRegistry::apply($op, $a);
                $match = ($gResult === null && $aResult === null)
                    || (is_numeric($gResult) && is_numeric($aResult)
                        && abs((float)$gResult - (float)$aResult) < 0.0001);
                if (! $match) {
                    $mismatches[] = "$op($a): G=" . ($gResult ?? 'null') . " A=" . ($aResult ?? 'null');
                }
            }
        }
        $this->assertEmpty($mismatches, 'Unary: ' . implode('; ', $mismatches));
    }

    public function testGrammarAndAtomRegistryAgreeBinary(): void
    {
        $grammar = new Grammar();
        $ops = $grammar->all();
        $vals = [-2.0, 1.0, 3.0, 5.0];

        $mismatches = [];
        foreach ($ops as $op) {
            // Skip unary and semantic — tested elsewhere
            $unary = $grammar->getUnaryOps();
            if (in_array($op, $unary, true)) continue;
            if (in_array($op, Grammar::SEMANTIC_OPS, true)) continue;
            if (str_starts_with($op, 'K')) continue; // constants

            foreach ($vals as $a) {
                foreach ($vals as $b) {
                    $gResult = $grammar->apply($a, $b, $op);
                    $aResult = AtomRegistry::apply($op, $a, $b);
                    $match = ($gResult === null && $aResult === null)
                        || (is_numeric($gResult) && is_numeric($aResult)
                            && abs((float)$gResult - (float)$aResult) < 0.0001);
                    if (! $match) {
                        $mismatches[] = "$op($a,$b): G=" . (is_null($gResult) ? 'null' : $gResult)
                            . " A=" . (is_null($aResult) ? 'null' : $aResult);
                    }
                }
            }
        }
        $this->assertEmpty($mismatches, 'Binary: ' . implode('; ', $mismatches));
    }

    /**
     * Регрессия: sqrt был в прод-БД (5469 ops) и отсутствовал в applyCustom.
     * После вставки в grammar_ops — Grammar::apply должен совпадать с AtomRegistry.
     */
    public function testSqrtWorksAfterInsert(): void
    {
        $grammar = new Grammar();
        $grammar->add('sqrt', 'test');

        // Пересоздаём грамматику чтобы загрузить sqrt из БД
        $grammar2 = new Grammar();
        $this->assertContains('sqrt', $grammar2->all());

        $gResult = $grammar2->apply(16.0, 0.0, 'sqrt');
        $aResult = \BeeSwarm\Core\AtomRegistry::apply('sqrt', 16.0);

        $this->assertSame(4.0, $gResult, 'Grammar::apply(sqrt, 16) must be 4.0');
        $this->assertSame(4.0, $aResult);
    }

    /**
     * Отрицательный аргумент: оба возвращают null.
     */
    public function testSqrtNegativeReturnsNull(): void
    {
        $grammar = new Grammar();
        $grammar->add('sqrt', 'test');
        $grammar2 = new Grammar();

        $this->assertNull($grammar2->apply(-1.0, 0.0, 'sqrt'));
        $this->assertNull(\BeeSwarm\Core\AtomRegistry::apply('sqrt', -1.0));
    }

    /**
     * CONCERNS кластера B (05.08): inverse/log2/parity знал Grammar,
     * AtomRegistry — нет. Асимметрия: Search находил закон, heldout отвергал.
     */
    public function testUnarySymmetryAfterInsert(): void
    {
        $grammar = new Grammar();
        foreach (['inverse', 'log2', 'parity'] as $op) {
            $grammar->add($op, 'test');
        }
        $grammar2 = new Grammar();

        $cases = [
            ['inverse', 4.0, 0.25],
            ['log2', 8.0, 3.0],
            ['parity', 4.0, 1.0],
            ['parity', 3.0, -1.0],
        ];
        foreach ($cases as [$op, $arg, $expected]) {
            $gResult = $grammar2->apply($arg, 0.0, $op);
            $aResult = \BeeSwarm\Core\AtomRegistry::apply($op, $arg);
            $this->assertEqualsWithDelta($expected, $gResult, 0.0001, "Grammar $op($arg)");
            $this->assertEqualsWithDelta($expected, $aResult, 0.0001, "AtomRegistry $op($arg)");
        }
    }
}
