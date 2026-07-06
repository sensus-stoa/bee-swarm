<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Validation\LawValidator;
use BeeSwarm\Core\AtomRegistry;

/**
 * Story 07: Parsimony (HONEST_CRITERIA §1.3)
 *
 * @group disabled
 */
class ParsimonyTest extends TestCase
{
    /** selectSimplest() — из нескольких кандидатов выбирает простейший */
    public function test_select_simplest_exists(): void
    {
        $this->assertTrue(
            method_exists(LawValidator::class, 'selectSimplest'),
            'LawValidator must implement selectSimplest()'
        );
    }

    /** 'add' проще чем 'add(min)' — выбирается add */
    public function test_simpler_atom_selected(): void
    {
        $candidates = [
            ['atom' => 'add(min)', 'cv' => 0, 'cv_train' => 0, 'cv_holdout' => 0, 'mode' => 'compose'],
            ['atom' => 'add', 'cv' => 0, 'cv_train' => 0, 'cv_holdout' => 0, 'mode' => 'binary'],
            ['atom' => 'min', 'cv' => 0, 'cv_train' => 0, 'cv_holdout' => 0, 'mode' => 'binary'],
        ];

        $result = LawValidator::selectSimplest($candidates);

        $this->assertIsArray($result);
        $this->assertCount(1, $result,
            'Parsimony must select exactly one candidate');
        $this->assertSame('add', $result[0]['atom'],
            'Simplest atom (add, 1 node) must be selected over add(min) (3 nodes)');
    }

    /** Все одинаковой сложности → возвращаются все */
    public function test_equal_complexity_returns_all(): void
    {
        $candidates = [
            ['atom' => 'add', 'cv' => 0, 'mode' => 'binary'],
            ['atom' => 'mul', 'cv' => 0, 'mode' => 'binary'],
        ];

        $result = LawValidator::selectSimplest($candidates);

        $this->assertCount(2, $result,
            'Equal complexity candidates must all be returned');
    }
}
