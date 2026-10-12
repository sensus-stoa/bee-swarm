<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * DISCOVERY-DEPTH-3 (09.08, ЭКСП-022f): улей вызывал Search::find с depth=2 —
 * L2L1-мост (depth>=3) не активен → (x0+x1)×x2 невыразим в улье → 0 законов.
 * Прямой find (depth=3) находит: ((x0addx1)mulx2) cv=0.
 */
class DiscoveryDepth3Test extends TestCase
{
    // FIX 11.10 (suite env-leak): putenv('SEARCH_DEPTH_MAX') внутри тестов
    // СНИМАЕТ переменную до конца воркера — последующие классы в том же
    // воркере (IntegrationLawTest и др.) получают getenv()=false →
    // DiscoveryEngine default maxDepth=4 → другие законы/таймауты.
    // Задокументированный питфолл EnsembleWiringLiveTest («678 скипов»).
    // Гигиена: tearDown восстанавливает phpunit-force значения.
    protected function tearDown(): void
    {
        putenv('SEARCH_DEPTH_MAX=2');
        putenv('SEARCH_BEAM_K=10');
        putenv('SEARCH_ESCALATED');
        putenv('SWARM_DB_PATH=:memory:');
        parent::tearDown();
    }

    public function testEngineFindsThreeFeatureLaw(): void
    {
        $rows = [];
        $h = fopen(__DIR__ . '/fixtures/forager/b_quad.csv', 'r');
        if ($h === false) {
            $this->markTestSkipped('fixture missing');
        }
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== false) {
            $rows[] = array_map('floatval', $r);
        }
        fclose($h);

        $X = array_map(fn ($r) => [$r[0], $r[1], $r[2]], $rows);
        $y = array_map(fn ($r) => $r[3], $rows);

        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div', 'max', 'min']);
        $engine = new DiscoveryEngine($g);
        // phpunit.xml ограничивает SEARCH_DEPTH_MAX=2 — тест явно поднимает
        putenv('SEARCH_DEPTH_MAX=3');
        try {
            [$found] = $engine->discover($X, $y, ['add', 'sub', 'mul', 'div'], 0.15, ['x0', 'x1', 'x2', 'y']);
        } finally {
            putenv('SEARCH_DEPTH_MAX');
        }

        $this->assertNotEmpty($found, 'engine must find (x0+x1)×x2 at depth 3');
        // M1-EXT v2 WU-2 (11.10): raw-pair merge расширил выразимость d=2 —
        // распределённая форма (x0x2+x1x2) теперь находится на depth-2
        // (EXP-040-EXT: sum-of-products = класс d=2). found[0] может быть
        // ЕЙ, а не факторизованной. Контракт: закон двухуровневый (L1⊕L1)
        // ИЛИ сумма двух продуктов — оба cv=0 на фикстуре.
        $atoms = array_map(fn ($c) => $c['atom'] ?? '', $found);
        $twoLevel = array_filter($atoms, fn (string $a): bool =>
            (bool) (preg_match('/\(x0.{1,12}x1\).{1,6}x2/', $a)
                || preg_match('/\(x\d+.{1,8}x2\).{1,6}\(x\d+.{1,8}x2\)/', $a)));
        $this->assertNotEmpty($twoLevel, 'two-level law family expected: ' . json_encode($atoms));
    }

    public function testDepthParameterControlsExpressiveness(): void
    {
        $rows = [];
        $h = fopen(__DIR__ . '/fixtures/forager/b_quad.csv', 'r');
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== false) {
            $rows[] = array_map('floatval', $r);
        }
        fclose($h);
        $X = array_map(fn ($r) => [$r[0], $r[1], $r[2]], $rows);
        $y = array_map(fn ($r) => $r[3], $rows);
        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div', 'max', 'min']);
        $engine = new DiscoveryEngine($g);

        // ADAPTIVE-DEPTH (09.08): depth=2 как НАЧАЛЬНАЯ эскалируется до 3
        // при пустом результате — двухуровневый закон находится
        putenv('SEARCH_DEPTH_MAX=3');
        try {
            [$found2] = $engine->discover($X, $y, ['add', 'sub', 'mul', 'div'], 0.15, ['x0', 'x1', 'x2', 'y'], depth: 2);
        } finally {
            putenv('SEARCH_DEPTH_MAX');
        }
        $atoms2 = array_map(fn ($c) => $c['atom'] ?? '', $found2);
        // M1-EXT v2 WU-2 (11.10): raw-pair merge РАСШИРИЛ выразимость d=2 —
        // распределённая форма (x0x2+x1x2) теперь находится на depth-2
        // (EXP-040-EXT dot: sum-of-products = класс d=2). Поэтому d=2 здесь
        // ЛЕГАЛЬНО находит ((x1mulx2)add(x0mulx2)) и ADAPTIVE-DEPTH break
        // («составной закон cv≤0.05») не эскалирует. Эскалационный контракт
        // WU-2 перекрыл: глубинная граница теперь между распределённой
        // формой (d=2) и фактор-формой (d=3). Старый контракт «d=2 ничего
        // не находит» устарел вместе с WU-2.
        $this->assertNotEmpty($atoms2, 'd=2 must find sum-of-products: ' . json_encode($atoms2));

        // Жёсткий предел SEARCH_DEPTH_MAX=2: НЕ выражает двухуровневый
        putenv('SEARCH_DEPTH_MAX=2');
        try {
            [$found3] = $engine->discover($X, $y, ['add', 'sub', 'mul', 'div'], 0.15, ['x0', 'x1', 'x2', 'y'], depth: 2);
        } finally {
            putenv('SEARCH_DEPTH_MAX');
        }
        $atoms3 = array_map(fn ($c) => $c['atom'] ?? '', $found3);
        $this->assertNotContains(
            '((x0addx1)mulx2)',
            $atoms3,
            'SEARCH_DEPTH_MAX=2 must not express (x0+x1)×x2: ' . json_encode($atoms3)
        );
    }
}
