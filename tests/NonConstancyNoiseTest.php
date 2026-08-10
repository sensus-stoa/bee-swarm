<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * NON-CONSTANCY (10.08, ЭКСП-026/MOEX): константные псевдозаконы проходят
 * CV через shift-нормализацию: знакопеременный y → shift=min(y)−1 →
 * ratio=(pred−shift)/(y−shift) сглаживается → (x0/R+x0) на ЧИСТОМ ШУМЕ
 * даёт CV=0.028 < 0.15 → found=true. RED: шум → НЕ должно быть находок.
 */
class NonConstancyNoiseTest extends TestCase
{
    public function testNoiseRejectedWithSignedY(): void
    {
        // Чистый шум: x0 и y НЕЗАВИСИМЫ, y знакопеременный (как MOEX ret)
        srand(7);
        $X = [];
        $y = [];
        for ($i = 0; $i < 40; $i++) {
            $X[] = [rand() / 1000];
            $y[] = -0.05 + (rand() / getrandmax()) * 0.1;
        }
        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div', 'max', 'min']);
        putenv('SEARCH_BEAM_K=10');
        putenv('SEARCH_DEPTH_MAX=2');

        [$found] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertFalse($found,
            'шум со знакопеременным y НЕ должен давать законы (псевдозакон '
            . '(x0/R+x0) CV=0.028 — shift-артефакт, ЭКСП-026: 260/260 на MOEX!)');
    }

    public function testRealLawWithSignedYNotRejected(): void
    {
        // Позитивный контроль (CONCERNS deleg_1d163bae): реальный закон
        // со знакопеременным y НЕ должен резаться null-фильтром:
        // y = x0 + x1 + ε, x1 ∈ [−1, 1] (знакопеременный), ε = 0.02.
        srand(11);
        $X = [];
        $y = [];
        for ($i = 0; $i < 40; $i++) {
            $x1 = -1.0 + (rand() / getrandmax()) * 2.0;
            $X[] = [($i % 10) + 1.0, $x1];
            $y[] = ($i % 10) + 1.0 + $x1 + ((rand() / getrandmax()) - 0.5) * 0.04;
        }
        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div', 'max', 'min']);
        putenv('SEARCH_BEAM_K=10');
        putenv('SEARCH_DEPTH_MAX=2');

        [$found, $cv] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found,
            'реальный закон y=x0+x1+ε со знакопеременным y ДОЛЖЕН быть найден '
            . '(null-фильтр не должен резать сигнал: cv=' . round($cv, 4) . ')');
        $this->assertLessThan(0.15, $cv, 'реальный закон: CV < 0.15');
    }
}
