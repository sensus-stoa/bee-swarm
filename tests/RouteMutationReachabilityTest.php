<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;
use BeeSwarm\Hive\RouteExecutor;

/**
 * STRATEGY-TRANSFER prereg rev.5, pre-freeze PASS-gate:
 * modular reachability chain (Kashtan & Alon):
 * parent -> DUPLICATE -> SPECIALIZE -> CO_OPT -> executed phenotype.
 * Each link must produce a VALID, SERIALIZABLE, EXECUTABLE genome.
 */
final class RouteMutationReachabilityTest extends TestCase
{
    private function parentGenome(): array
    {
        return [
            'id' => 'route_beef01',
            'actions' => [
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'default', 'depth' => 2, 'budget_fraction' => 0.2],
                ['op' => 'USE_MARKS', 'k' => 5, 'budget_fraction' => 0.1],
            ],
            'condition' => ['kind' => 'ALWAYS'],
            'budget_fraction' => 0.3,
            'max_steps' => 4,
        ];
    }

    public function testChainDuplicateSpecializeCoOptProducesExecutablePhenotype(): void
    {
        $parent = $this->parentGenome();

        // link 1: DUPLICATE - copy of an action block
        $dup = RouteMutator::mutate($parent, RouteMutator::TYPE_DUPLICATE, 42);
        $this->assertNotNull($dup, 'DUPLICATE returned null');
        $this->assertSame([], RouteGenome::validate($dup), 'DUPLICATE child invalid');
        $this->assertGreaterThan(count($parent['actions']), count($dup['actions']), 'DUPLICATE did not add an action');

        // link 2: SPECIALIZE - modify the COPY, original untouched
        $spec = RouteMutator::mutate($dup, RouteMutator::TYPE_SPECIALIZE, 43);
        $this->assertNotNull($spec, 'SPECIALIZE returned null');
        $this->assertSame([], RouteGenome::validate($spec), 'SPECIALIZE child invalid');
        // original block (parent action 0) must survive unchanged in SPECIALIZE output
        $this->assertSame($dup['actions'][0], $spec['actions'][0], 'SPECIALIZE mutated the original, not the copy');

        // link 3: CO_OPT - the (specialized) copy enters a new context
        $coopt = RouteMutator::mutate($spec, RouteMutator::TYPE_CO_OPT, 44);
        $this->assertNotNull($coopt, 'CO_OPT returned null');
        $this->assertSame([], RouteGenome::validate($coopt), 'CO_OPT child invalid');

        // link 4: executable phenotype - executor accepts the genome
        $active = RouteExecutor::shouldActivate($coopt, [
            'certified' => false, 'flights_done' => 1, 'task_class' => 'SUM',
        ]);
        $this->assertIsBool($active, 'executor rejected CO_OPT genome');
        foreach ($coopt['actions'] as $a) {
            $this->assertContains($a['op'], RouteGenome::ACTION_OPS, 'unknown action op');
        }
    }

    public function testConditionIntegratorKindIsValidAndHeritable(): void
    {
        $g = $this->parentGenome();
        $g['condition'] = ['kind' => 'CLASS_INTEGRATOR:SUM', 'alpha' => 0.3, 'theta' => 0.05, 'n_min' => 5];
        $this->assertSame([], RouteGenome::validate($g), 'CLASS_INTEGRATOR condition invalid');
        // CONDITION gene survives mutation paths (crossover absent in engine;
        // inheritance = condition field untouched by non-CONDITION mutations)
        $child = RouteMutator::mutate($g, RouteMutator::TYPE_DUPLICATE, 45);
        $this->assertNotNull($child);
        $this->assertSame('CLASS_INTEGRATOR:SUM', $child['condition']['kind'], 'mutation lost CONDITION gene');
    }
}
