<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use PHPUnit\Framework\TestCase;

/**
 * STRATEGY-TRANSFER prereg rev.5, edit 1: C.execution = A.execution_actual.
 * A logs actual execution spend (wall + actions); C receives EXACTLY that
 * figure as its execution budget; A's evolution spend returns to C as
 * unspent and does NOT enter the A-vs-C comparison.
 */
final class BudgetAccountantTest extends TestCase
{
    public function testCReceivesExactlyAExecutionActual(): void
    {
        $a = new BudgetAccountant(totalWall: 100.0, totalActions: 50);
        $a->chargeEvolution(wall: 30.0, actions: 10);
        $a->chargeExecution(wall: 45.0, actions: 25);
        $this->assertSame(45.0, $a->executionActualWall());
        $this->assertSame(25, $a->executionActualActions());
        $this->assertSame(25.0, $a->unspentWall()); // 100 - 30 - 45

        // C gets exactly A's actual execution budget - not TOTAL
        $c = BudgetAccountant::forC($a);
        $this->assertSame(45.0, $c->totalWall(), 'C budget must equal A.execution_actual');
        $this->assertSame(25, $c->totalActions());
        $this->assertSame(45.0, $c->unspentWall(), 'fresh C: full execution budget available');
    }

    public function testEvolutionSpendNeverEntersComparison(): void
    {
        $a = new BudgetAccountant(totalWall: 100.0, totalActions: 50);
        $a->chargeEvolution(wall: 60.0, actions: 20); // heavy evolution
        $a->chargeExecution(wall: 40.0, actions: 30);
        $c = BudgetAccountant::forC($a);
        // C runs with A's execution figure; A's evolution cost does not shrink C
        $this->assertSame(40.0, $c->totalWall());
        $this->assertSame(30, $c->totalActions());
        $this->assertSame(60.0, $a->evolutionWall(), 'evolution spend must be tracked separately');
    }

    public function testSpentGuard(): void
    {
        $a = new BudgetAccountant(totalWall: 10.0, totalActions: 5);
        $this->expectException(\RuntimeException::class);
        $a->chargeExecution(wall: 11.0, actions: 1);
    }
}
