<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Validation\LawValidator;

/**
 * Story 07: Parsimony (HONEST_CRITERIA §1.3)
 */
class ParsimonyTest extends TestCase
{
    /**
     * selectSimplest() — из нескольких кандидатов выбирает простейший
     */
    public function testSelectSimplestExists(): void
    {
        $this->assertTrue(
            method_exists(LawValidator::class, 'selectSimplest'),
            'LawValidator must implement selectSimplest()'
        );
    }

    /**
     * 'add' проще чем 'add(min)' — выбирается add
     */
    public function testSimplerAtomSelected(): void
    {
        $candidates = [
            [
                'atom' => 'add(min)',
                'cv' => 0,
                'cv_train' => 0,
                'cv_holdout' => 0,
                'mode' => 'compose',
            ],
            [
                'atom' => 'add',
                'cv' => 0,
                'cv_train' => 0,
                'cv_holdout' => 0,
                'mode' => 'binary',
            ],
        ];

        $result = LawValidator::selectSimplest($candidates);

        $this->assertCount(
            1,
            $result,
            'Parsimony must select exactly one candidate (add, not add(min))'
        );
        $this->assertSame(
            'add',
            $result[0]['atom'],
            'Simplest atom (add, 1 node) selected over add(min) (3 nodes)'
        );
    }

    /**
     * Все одинаковой сложности → возвращаются все
     */
    public function testEqualComplexityReturnsAll(): void
    {
        $candidates = [
            [
                'atom' => 'add',
                'cv' => 0,
                'mode' => 'binary',
            ],
            [
                'atom' => 'mul',
                'cv' => 0,
                'mode' => 'binary',
            ],
        ];

        $result = LawValidator::selectSimplest($candidates);

        $this->assertCount(
            2,
            $result,
            'Equal complexity candidates must all be returned'
        );
    }
}
