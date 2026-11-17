<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteLedger;
use PHPUnit\Framework\TestCase;

/**
 * WU ROAD-YIELD (ветка b' — fitness, чувствительный к дороге):
 * Individual-level селекция с дорожными членами. Топология учитывает
 * clone-only fp-scoped стигмергию: польза дороги течёт ВНУТРИ задачи
 * между членами популяции (не lineage — задачи посещаются один раз).
 * Два непрерывных члена:
 *  - ROAD_YIELD: mean(topk_useful) — сколько прочитанных меток реально
 *    помогли поиску (evolvednm физически = 0: дорога выключена);
 *  - EFFICIENCY: mean(unspent) — остаток бюджета (дорожные маршруты
 *    решают быстрее — переиспользуют материал).
 * Оба — из существующей телеметрии, непрерывные (в отличие от single-bit
 * cert), оба доступны в точке recordOutcome.
 * Веса заморожены для E1-lite кампании (prereg PREREG_E1LITE_ROADYIELD):
 * grid не гоняется в lite; PASS → полный Stage A с сеткой весов.
 */
final class RouteLedgerRoadYieldTest extends TestCase
{
    private function genome(string $id): array
    {
        return RouteGenome::create(
            $id,
            '',
            0,
            ['kind' => 'ALWAYS'],
            [['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'default']],
            0.2,
            1
        );
    }

    public function testWeightsFrozenForLite(): void
    {
        $this->assertSame(0.5, RouteLedger::EFFICIENCY_WEIGHT);
        $this->assertSame(0.2, RouteLedger::ROAD_YIELD_WEIGHT);
    }

    public function testRecordOutcomeAccumulatesRoadYield(): void
    {
        $l = new RouteLedger();
        $l->register($this->genome('route_aaaa1111'));
        $l->recordOutcome('route_aaaa1111', 't1', 0.0, false, 1.0, 0.2, 3.0);
        $l->recordOutcome('route_aaaa1111', 't2', 0.0, false, 1.0, 0.2, 4.0);
        $g = $l->get('route_aaaa1111');
        $this->assertSame(7.0, $g['record']['road_yield_sum'], 'road_yield_sum копится');
        $this->assertSame(2, $g['record']['tasks_seen']);
    }

    public function testScoreRewardsRoadYield(): void
    {
        $lA = new RouteLedger();
        $lB = new RouteLedger();
        $lA->register($this->genome('route_aaaa1111'));
        $lB->register($this->genome('route_bbbb2222'));
        // Идентичные delta/wall/unspent, разный road yield (дорога помогла A).
        for ($i = 0; $i < 3; $i++) {
            $lA->recordOutcome('route_aaaa1111', 't', 0.0, false, 1.0, 0.1, 5.0);
            $lB->recordOutcome('route_bbbb2222', 't', 0.0, false, 1.0, 0.1, 0.0);
        }
        $this->assertGreaterThan(
            $lB->score($lB->get('route_bbbb2222')),
            $lA->score($lA->get('route_aaaa1111')),
            'дорожная польза должна поднимать score (сейчас селекция слепа)'
        );
        // Амплитуда заморожена: вклад = 0.2 * 5.0 = 1.0 (дельта-форма от float).
        $this->assertEqualsWithDelta(
            1.0,
            $lA->score($lA->get('route_aaaa1111')) - $lB->score($lB->get('route_bbbb2222')),
            1e-9
        );
    }

    public function testScoreRewardsUnspentEfficiency(): void
    {
        $lA = new RouteLedger();
        $lB = new RouteLedger();
        $lA->register($this->genome('route_aaaa1111'));
        $lB->register($this->genome('route_bbbb2222'));
        for ($i = 0; $i < 2; $i++) {
            $lA->recordOutcome('route_aaaa1111', 't', 0.0, false, 1.0, 0.8, 0.0);
            $lB->recordOutcome('route_bbbb2222', 't', 0.0, false, 1.0, 0.2, 0.0);
        }
        $this->assertGreaterThan(
            $lB->score($lB->get('route_bbbb2222')),
            $lA->score($lA->get('route_aaaa1111')),
            'эффективность (неистраченный бюджет) должна поднимать score'
        );
        // Вклад = 0.5 * (0.8 - 0.2) = 0.3 ровно.
        $this->assertSame(
            0.3,
            round($lA->score($lA->get('route_aaaa1111')) - $lB->score($lB->get('route_bbbb2222')), 6)
        );
    }

    public function testLegacySignatureStillWorks(): void
    {
        // 6-аргументный вызов (старые тесты/каналы) валиден: road_yield = 0.
        $l = new RouteLedger();
        $l->register($this->genome('route_aaaa1111'));
        $l->recordOutcome('route_aaaa1111', 't', 0.10, false, 2.0, 0.3);
        $g = $l->get('route_aaaa1111');
        $this->assertSame(0.0, $g['record']['road_yield_sum']);
        $this->assertSame([], RouteGenome::validate($g));
    }
}
