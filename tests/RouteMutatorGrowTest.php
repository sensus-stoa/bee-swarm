<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;
use PHPUnit\Framework\TestCase;

/**
 * WU GROW (E1-lite → B++): шестой тип мутации — вставка одного действия
 * из словаря в случайную позицию. Прекондиция B++: эволюция не может
 * достроить лестницу F-M-F-M-F из сидов 1-3 действий (REPLACE/SWAP
 * сохраняют длину, SHORTEN режет). GROW даёт рост длины с капом
 * MAX_ACTIONS. Fallback на REPLACE на капе — по прецеденту SWAP
 * (<2 действий), null убивал бы линедж зря.
 */
final class RouteMutatorGrowTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array<string, mixed>
     */
    private function genome(array $actions, int $maxSteps = 0): array
    {
        return RouteGenome::create(
            'route_deadbeef',
            '',
            0,
            ['kind' => 'ALWAYS'],
            $actions,
            0.30,
            $maxSteps > 0 ? $maxSteps : count($actions)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fmf(): array
    {
        return [
            ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'],
        ];
    }

    private function sig(array $g): string
    {
        $out = [];
        foreach ($g['actions'] as $a) {
            $out[] = $a['op'] === 'FLIGHT' ? 'F' . $a['depth'] : ($a['op'] === 'USE_MARKS' ? 'M' : 'B');
        }

        return implode('', $out);
    }

    public function testGrowAddsExactlyOneAction(): void
    {
        mt_srand(2050);
        $g = $this->genome($this->fmf());
        $child = RouteMutator::mutate($g, RouteMutator::TYPE_GROW);
        $this->assertNotNull($child);
        $this->assertCount(4, $child['actions'], 'grow добавляет ровно одно действие');
        $this->assertSame([], RouteGenome::validate($child), 'потомок GROW валиден по схеме');
        $this->assertSame('route_deadbeef', $child['parent_id']);
        $this->assertSame(1, $child['generation']);
        $this->assertSame(0, $child['record']['tasks_seen'], 'record потомка обнулён');
    }

    public function testGrowKeepsActionsAsPrefixMultiset(): void
    {
        // Все родительские действия сохраняются (вставка, не замена):
        // мультимножество родителя — подмножество мультимножества потомка.
        mt_srand(2051);
        $g = $this->genome($this->fmf());
        $child = RouteMutator::mutate($g, RouteMutator::TYPE_GROW);
        $this->assertNotNull($child);
        $opsParent = array_map(fn (array $a): string => $a['op'], $g['actions']);
        $opsChild = array_map(fn (array $a): string => $a['op'], $child['actions']);
        foreach (array_count_values($opsParent) as $op => $n) {
            $this->assertGreaterThanOrEqual($n, array_count_values($opsChild)[$op] ?? 0);
        }
    }

    public function testGrowCanInsertBetweenFlights(): void
    {
        // Цель reachability (0h-чек A/C, probe_mutator_reach): M между
        // полётами достижима. Из F2F2M вставка M в позицию 1 даёт F2MF2M.
        mt_srand(2052);
        $f2 = ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'];
        $m5 = ['op' => 'USE_MARKS', 'k' => 5];
        $g = $this->genome([$f2, $f2, $m5]);
        $hits = 0;
        for ($i = 0; $i < 500; $i++) {
            $child = RouteMutator::mutate($g, RouteMutator::TYPE_GROW);
            $this->assertNotNull($child);
            if (str_contains($this->sig($child), 'F2MF2')) {
                $hits++;
            }
        }
        // P(вставка M в позицию 1) ≈ 1/4 · 1/3 за итерацию → мат. ожидание ~40+
        // из 500; ноль попусту невозможен (P(0) < 1e-30).
        $this->assertGreaterThan(0, $hits, 'M-mid недостижима вставкой — GROW не решает reachability');
    }

    public function testGrowAtMaxActionsFallsBackToReplace(): void
    {
        mt_srand(2053);
        $a = ['op' => 'LOCAL_BIRTH'];
        $f2 = ['op' => 'FLIGHT', 'depth' => 1, 'grammar' => 'base', 'beam' => 'beam10nl'];
        $g = $this->genome([$a, $f2, $a, $f2, $a, $f2]); // 6 = MAX_ACTIONS
        $child = RouteMutator::mutate($g, RouteMutator::TYPE_GROW);
        $this->assertNotNull($child, 'на капе GROW не убивает линедж (fallback REPLACE, прецедент SWAP)');
        $this->assertCount(6, $child['actions']);
        $this->assertSame([], RouteGenome::validate($child));
    }

    public function testGrowBumpsMaxSteps(): void
    {
        mt_srand(2054);
        $g = $this->genome($this->fmf(), 3);
        $child = RouteMutator::mutate($g, RouteMutator::TYPE_GROW);
        $this->assertNotNull($child);
        $this->assertSame(4, $child['max_steps'], 'max_steps растёт вместе с действиями');
        $this->assertLessThanOrEqual(RouteGenome::MAX_ACTIONS, $child['max_steps']);
    }

    public function testGrowNeverBreaksSchemaAcrossSeeds(): void
    {
        $f2 = ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'];
        $m5 = ['op' => 'USE_MARKS', 'k' => 5];
        for ($seed = 3000; $seed < 3050; $seed++) {
            mt_srand($seed);
            $g = $this->genome([$f2, $m5]);
            $child = RouteMutator::mutate($g, RouteMutator::TYPE_GROW);
            $this->assertNotNull($child, "seed {$seed}: GROW вымерла неожиданно");
            $this->assertSame([], RouteGenome::validate($child), "seed {$seed}: невалидный потомок");
        }
    }
}
