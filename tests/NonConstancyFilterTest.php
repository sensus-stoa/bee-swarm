<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\NonConstancyFilter;
use BeeSwarm\Core\Search;
use PHPUnit\Framework\TestCase;

/**
 * NON-CONSTANCY refactor (11.08): K1/K2/R-кейсы фильтра отдельным классом.
 * Фильтр отделяет «физические инварианты грамматики» (K1≡1.0, K2≡2.0)
 * от «рыночных эффектов»: псевдозакон (константа+фича) имеет t/null≈1.0
 * (формула не зависит от порядка y) → REJECT при ratio=0.55; реальный
 * закон со знакопеременным y имеет t/null<<0.55 → НЕ reject.
 */
class NonConstancyFilterTest extends TestCase
{
    private function noiseData(int $n = 60, int $seed = 7): array
    {
        srand($seed);
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $X[] = [rand() / getrandmax()]; // 0..1 (rand()/1000 = до 2M — масштаб-баг!)
            $y[] = -0.05 + (rand() / getrandmax()) * 0.1; // знакопеременный
        }
        return [$X, $y];
    }

    private function tFor(string $formula, array $X, array $y): float
    {
        return Search::testCv(
            $formula, $X, $y, Search::stddev($y), count($y), null, $X, [], []
        );
    }

    public function testK1PseudoLawRejected(): void
    {
        [$X, $y] = $this->noiseData();
        $t = $this->tFor('(K1+x0)', $X, $y);
        $nullCv = NonConstancyFilter::nullMedianCv('(K1+x0)', $X, $y, Search::stddev($y), count($y), null, $X, [], []);
        // t < 9.99: формула прошла std-фильтр (x0 шумит), CV низкий (shift-артефакт)
        $this->assertLessThan(9.98, $t, 'псевдозакон должен дойти до null-фильтра');
        $this->assertTrue(
            NonConstancyFilter::rejects($t, $nullCv, 0.55),
            '(K1+x0) — константный псевдозакон, t/null≈1.0 ≥ 0.55 → REJECT'
        );
    }

    public function testK2PseudoLawRejected(): void
    {
        [$X, $y] = $this->noiseData(60, 9);
        $t = $this->tFor('(K2+x0)', $X, $y);
        $nullCv = NonConstancyFilter::nullMedianCv('(K2+x0)', $X, $y, Search::stddev($y), count($y), null, $X, [], []);
        $this->assertTrue(
            NonConstancyFilter::rejects($t, $nullCv, 0.55),
            '(K2+x0) — константный псевдозакон → REJECT'
        );
    }

    public function testRealLawWithSignedYNotRejected(): void
    {
        // y = 2·x0 + шум, знакопеременный (сдвиг на среднее)
        srand(42);
        $X = [];
        $raw = [];
        for ($i = 0; $i < 80; $i++) {
            $x = rand() / getrandmax(); // 0..1
            $X[] = [$x];
            $raw[] = 2.0 * $x + (rand() / getrandmax() - 0.5) * 0.05; // СИЛЬНЫЙ закон (t/null≈0.1-0.2)
        }
        $mean = array_sum($raw) / count($raw);
        $y = array_map(fn ($v) => $v - $mean, $raw); // знакопеременный
        $t = $this->tFor('x0', $X, $y);
        $nullCv = NonConstancyFilter::nullMedianCv('x0', $X, $y, Search::stddev($y), count($y), null, $X, [], []);
        $this->assertFalse(
            NonConstancyFilter::rejects($t, $nullCv, 0.55),
            'реальный закон (y=2·x0+шум) со знакопеременным y НЕ должен резаться'
        );
    }
}
