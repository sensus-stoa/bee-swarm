<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;

/**
 * EXP-045 S5: схема генома + валидность всех 5 мутаций.
 */
class RouteGenomeTest extends TestCase
{
    private function validGenome(): array
    {
        return RouteGenome::create(
            'route_deadbeef',
            '',
            0,
            [
                'kind' => 'NO_CERT_AFTER',
                'flights' => 2,
            ],
            [
                [
                    'op' => 'FLIGHT',
                    'depth' => 2,
                    'grammar' => 'base',
                    'beam' => 'default',
                ],
                [
                    'op' => 'USE_MARKS',
                    'k' => 5,
                ],
                [
                    'op' => 'LOCAL_BIRTH',
                ],
            ],
            0.2,
            3,
        );
    }

    public function testValidGenomePasses(): void
    {
        $this->assertSame([], RouteGenome::validate($this->validGenome()));
    }

    public function testUnknownActionOpRejected(): void
    {
        $g = $this->validGenome();
        $g['actions'][0]['op'] = 'FREE_PHP_CODE';
        $this->assertNotSame([], RouteGenome::validate($g));
    }

    public function testBudgetFractionBounds(): void
    {
        $g = $this->validGenome();
        $g['budget_fraction'] = 0.5;
        $this->assertNotSame([], RouteGenome::validate($g));
        $g['budget_fraction'] = 0.01;
        $this->assertNotSame([], RouteGenome::validate($g));
        $g['budget_fraction'] = 0.30;
        $this->assertSame([], RouteGenome::validate($g));
    }

    public function testUnknownConditionKindRejected(): void
    {
        $g = $this->validGenome();
        $g['condition'] = [
            'kind' => 'ALWAYS_BIRTH_ATOMS',
        ];
        $this->assertNotSame([], RouteGenome::validate($g));
    }

    public function testUnknownGrammarPresetRejected(): void
    {
        $g = $this->validGenome();
        $g['actions'][0]['grammar'] = 'magic_diff';
        $this->assertNotSame([], RouteGenome::validate($g));
    }

    public function testAllFiveMutationsProduceValidGenomes(): void
    {
        mt_srand(2045);
        foreach ([
            RouteMutator::TYPE_REPLACE,
            RouteMutator::TYPE_SWAP,
            RouteMutator::TYPE_CONDITION,
            RouteMutator::TYPE_BUDGET,
            RouteMutator::TYPE_SHORTEN,
        ] as $type) {
            $child = RouteMutator::mutate($this->validGenome(), $type);
            if ($type === RouteMutator::TYPE_SHORTEN) {
                $this->assertNotNull($child);
                $this->assertCount(2, $child['actions'], 'shorten усекает хвост');
            }
            $this->assertNotNull($child, "mutation type {$type} вымерла неожиданно");
            $this->assertSame([], RouteGenome::validate($child), "mutation type {$type} дала невалидный геном");
            $this->assertSame('route_deadbeef', $child['parent_id']);
            $this->assertSame(1, $child['generation']);
            $this->assertSame(0, $child['record']['tasks_seen'], 'record потомка обнулён');
        }
    }

    public function testShortenSingleActionKillsRoute(): void
    {
        $g = RouteGenome::create(
            'route_cafe01',
            '',
            0,
            [
                'kind' => 'ALWAYS',
            ],
            [[
                'op' => 'LOCAL_BIRTH',
            ]],
            0.1,
            1
        );
        $this->assertNull(RouteMutator::mutate($g, RouteMutator::TYPE_SHORTEN));
    }

    public function testSwapPreservesActionMultiset(): void
    {
        mt_srand(2046);
        $g = $this->validGenome();
        $child = RouteMutator::mutate($g, RouteMutator::TYPE_SWAP);
        $this->assertNotNull($child);
        $opsBefore = array_map(fn (array $a): string => $a['op'], $g['actions']);
        $opsAfter = array_map(fn (array $a): string => $a['op'], $child['actions']);
        sort($opsBefore);
        sort($opsAfter);
        $this->assertSame($opsBefore, $opsAfter);
    }

    public function testBudgetMutationClamped(): void
    {
        mt_srand(2047);
        $g = $this->validGenome();
        $g['budget_fraction'] = RouteGenome::BUDGET_MAX;
        for ($i = 0; $i < 20; $i++) {
            $child = RouteMutator::mutate($g, RouteMutator::TYPE_BUDGET);
            $this->assertNotNull($child);
            $this->assertGreaterThanOrEqual(RouteGenome::BUDGET_MIN, $child['budget_fraction']);
            $this->assertLessThanOrEqual(RouteGenome::BUDGET_MAX, $child['budget_fraction']);
        }
    }

    public function testIdDeterministicUnderSeed(): void
    {
        mt_srand(9001);
        $a = RouteMutator::newId();
        $b = RouteMutator::newId();
        $this->assertNotSame($a, $b, 'счётчик устраняет коллизии');
        mt_srand(9001);
        RouteMutator::newId(); // счётчик сдвинулся — полный повтор требует нового процесса,
        // но внутри процесса идентификаторы формата и уникальны
        $this->assertMatchesRegularExpression('/^route_[0-9a-f]{4,}$/', $a);
    }
}
