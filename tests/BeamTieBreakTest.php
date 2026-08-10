<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * BEAM-TIE-BREAK (09.08, CONCERNS deleg_f5c43e37): при равном CV в beam
 * выживает КОРОЧАЯ форма (B-атом vs add) — иначе exact-tie забивал
 * слоты beam add-формами → B-атом не попадал в top-K → reuse=0.
 */
class BeamTieBreakTest extends TestCase
{
    public function testShorterFormWinsBeamSlotOnTie(): void
    {
        // Изоляция (10.08): другие тесты в процессе создают birth-атомы
        // (BC1-3) — их дубликаты-определения забивают bornBinary cap 3.
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        // B4=(x0+x1) — короткая форма; задача y=(x0+x1)×x2
        \BeeSwarm\Core\Grammar::staticAdd('B4', 'birth', '(x0addx1)', 'test');
        try {
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
            putenv('SEARCH_BEAM_K=10');
            try {
                [$found, , $formula] = Search::find($X, $y, $g, 3, null, 0.2, 0.15);
            } finally {
                putenv('SEARCH_BEAM_K');
            }

            $this->assertTrue($found, 'law must be found with beam');
            $this->assertStringContainsString('B4', $formula,
                'B-form must win beam tie: ' . $formula);
        } finally {
            \BeeSwarm\Infra\Database::get()->prepare(
                'DELETE FROM grammar_ops WHERE name = ? AND source = ?'
            )->execute(['B4', 'birth']);
        }
    }
}
