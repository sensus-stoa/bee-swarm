<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * СТАТУС 20.10: OOM-Killed при прогоне даже в облегчённом виде (причина не выяснена — инфра-вопрос);
 * фикс #1 DISCONFIRMED (0/8 на on/102). Не возвращать в tests/ до pair-лупа profiling.
 * WU_ROAD_V4_SPEC §6 / HANDOFF_SEED102.md.
 * WU fix #1 (EXP-045 сид-102, DIAGNOSIS.md рафинаж): raw products — атомы
 * языка — обязаны быть в pair-пуле БЕЗУСЛОВНО. Beam-path pool (top-K по
 * quick-cv) выпадал '(c2×c5)' на сиде-102 → mark⊕parent пара 2-term ⊕
 * (c2×c5) не собиралась (пара в пуле 15× невозможна без продукта) → 0/8.
 *
 * TRAIL_FIX = CSV 'pool,inject'; unset/'' = прежнее поведение (A/B-able);
 * 'pool' = raw products безусловно в пуле после beam-merge.
 *
 * $lastPoolFixAppended — телеметрия: список фактически добавленных форм.
 */
class TrailFixTest extends TestCase
{
    private const DOMAIN_FINGERPRINT = 'x0,x1,x2,x3,x4,x5';

    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::get();
        $db->exec('DELETE FROM trail_marks');
        $db->exec('DELETE FROM trail_ghosts');
        putenv('TRAIL_MARKS=1');
        putenv('SEARCH_RAWPAIR_POOL=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('TRAIL_FIX=');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
        Search::$lastPoolFixAppended = [];
    }

    protected function tearDown(): void
    {
        putenv('TRAIL_MARKS=');
        putenv('SEARCH_RAWPAIR_POOL=');
        putenv('SEARCH_NO_PREREG=');
        putenv('TRAIL_FIX=');
        Search::$trailFlightNo = 0;
        Search::$trailFlightInitialized = false;
        parent::tearDown();
    }

    private static function flight(string $fingerprint = self::DOMAIN_FINGERPRINT): void
    {
        // лёгкий полёт: append в пул происходит на построении пула (до
        // pair-лупа и scoring) — достаточен короткий бюджет и 60 строк
        mt_srand(42);
        $X = [];
        $y = [];
        for ($i = 0; $i < 60; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = 0.5 + mt_rand() / mt_getrandmax() * 1.5;
            }
            $X[] = $row;
            $y[] = $row[0] * $row[3] + (mt_rand() / mt_getrandmax() - 0.5) * 2.0;
        }
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);
        Search::find($X, $y, $g, 2, explode(',', $fingerprint), 0.0, 0.15, 5.0);
    }

    public function testTrailFixReadsEnvCsv(): void
    {
        putenv('TRAIL_FIX=pool,inject');
        $this->assertTrue(Search::trailFix('pool'));
        $this->assertTrue(Search::trailFix('inject'));
        $this->assertFalse(Search::trailFix('nmd'));
        putenv('TRAIL_FIX=');
        $this->assertFalse(Search::trailFix('pool'), 'unset = прежнее поведение');
    }

    public function testRawProductsAppendedToPoolWhenFixOn(): void
    {
        putenv('TRAIL_FIX=pool');
        self::flight();
        $appended = Search::$lastPoolFixAppended;
        // Полёт в x-пространстве (fingerprint x0..x5); конкретный состав
        // appended зависит от beam-отбора (top-K/хвосты). Контракт v2:
        // append добавляет ТОЛЬКО сырые ×-произведения, ≤ C(6,2)=15.
        $this->assertNotEmpty($appended, 'products missing from beam pool must be appended');
        $this->assertLessThanOrEqual(15, count($appended), 'append adds only raw products (C(6,2)=15 max)');
        foreach ($appended as $f) {
            $this->assertMatchesRegularExpression('/^\(x\d+×x\d+\)$/u', $f, 'append must add only raw ×-products, got ' . $f);
        }
    }

    public function testRawProductsNotAppendedWhenFixOff(): void
    {
        putenv('TRAIL_FIX=');
        self::flight();
        $this->assertSame([], Search::$lastPoolFixAppended, 'fix off → прежнее поведение (без append)');
    }
}
