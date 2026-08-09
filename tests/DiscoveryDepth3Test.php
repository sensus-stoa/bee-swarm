<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\DiscoveryEngine;
use BeeSwarm\Core\Grammar;

/**
 * DISCOVERY-DEPTH-3 (09.08, ЭКСП-022f): улей вызывал Search::find с depth=2 —
 * L2L1-мост (depth>=3) не активен → (x0+x1)×x2 невыразим в улье → 0 законов.
 * Прямой find (depth=3) находит: ((x0addx1)mulx2) cv=0.
 */
class DiscoveryDepth3Test extends TestCase
{
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
        $this->assertStringContainsString('mulx2', $found[0]['atom'] ?? '',
            'found law must be two-level: ' . json_encode($found[0] ?? []));
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
        $this->assertContains('((x0addx1)mulx2)', $atoms2,
            'adaptive depth must escalate 2→3: ' . json_encode($atoms2));

        // Жёсткий предел SEARCH_DEPTH_MAX=2: НЕ выражает двухуровневый
        putenv('SEARCH_DEPTH_MAX=2');
        try {
            [$found3] = $engine->discover($X, $y, ['add', 'sub', 'mul', 'div'], 0.15, ['x0', 'x1', 'x2', 'y'], depth: 2);
        } finally {
            putenv('SEARCH_DEPTH_MAX');
        }
        $atoms3 = array_map(fn ($c) => $c['atom'] ?? '', $found3);
        $this->assertNotContains('((x0addx1)mulx2)', $atoms3,
            'SEARCH_DEPTH_MAX=2 must not express (x0+x1)×x2: ' . json_encode($atoms3));
    }
}
