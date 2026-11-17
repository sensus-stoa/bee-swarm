<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * WU fix #1 (EXP-045 сид-102, DIAGNOSIS.md рафинаж): raw products — атомы
 * языка — обязаны быть в pair-пуле БЕЗУСЛОВНО. Beam-path pool (top-K по
 * quick-cv) выпадал '(c2×c5)' на сиде-102 → mark⊕parent пара 2-term ⊕
 * (c2×c5) не собиралась (пара в пуле 15× невозможна без продукта) → 0/8.
 *
 * TRAIL_FIX = CSV 'pool,inject,decoy'; unset/'' = прежнее поведение
 * (A/B-able); 'pool' = raw products безусловно в пуле после beam-merge;
 * 'decoy' = matched-size контроль (AMENDMENT_DOT4_DECOY, EXP-045): min/max
 * над raw-парами, ≤15, те же гейты и та же точка вставки — проверка
 * СПЕЦИФИЧНОСТИ материала (истинные продукты против любого обогащения).
 *
 * $lastPoolFixAppended — телеметрия: список фактически добавленных форм
 * (любой режим; no-op прогон ловится положительным счётчиком).
 *
 * @group slow — wall-clock класс: полёты time-budgeted (budgetSec 5s),
 * под paratest -p8 дедлайн бьёт внутри beam до append → ложный
 * assertNotEmpty fail (зафиксирован 20.10, серийный прогон OK 4/4).
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
        // SEARCH_BEAM_K пиннится ЯВНО: phpunit.xml ambient (force=true) убивается
        // соседями-классами в том же воркере (RouteExecutorWriteOnTest setUp/
        // tearDown делает putenv('SEARCH_BEAM_K') = unset; force=true работает
        // только на bootstrap процесса). Без пина beamK=0 → else-путь: pool =
        // полное слияние под капом (raw-pairs первыми) → все продукты уже в
        // seen → pool-fix/decoy append = 0 → assertNotEmpty падает. Парный
        // репро с write-on 30.10 (two-pass fast FAILURES:3); тот же симптом
        // «зафиксирован 20.10» — тогда лечили порядком, не причиной.
        putenv('SEARCH_BEAM_K=10');
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

    /**
     * Тот же лёгкий полёт, но грамматика с min/max — источник класса
     * $l1MinmaxKeys (decoy). '×' оставлен: декой не должен добавлять
     * произведения, даже когда они генерируются.
     */
    private static function flightWithMinMax(): void
    {
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
        $g->restrictTo(['+', '×', '−', '/', 'min', 'max']);
        Search::find($X, $y, $g, 2, explode(',', self::DOMAIN_FINGERPRINT), 0.0, 0.15, 5.0);
    }

    public function testTrailFixReadsEnvCsv(): void
    {
        putenv('TRAIL_FIX=pool,inject,decoy');
        $this->assertTrue(Search::trailFix('pool'));
        $this->assertTrue(Search::trailFix('inject'));
        $this->assertTrue(Search::trailFix('decoy'));
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

    /**
     * Decoy (AMENDMENT_DOT4_DECOY): matched-size нерелевантный append.
     * Контракт: только min/max над raw-парами, ≤15, ни одного произведения
     * (даже при их наличии в генераторе), append не пуст (guard от no-op).
     */
    public function testDecoyAppendsMinmaxNotProducts(): void
    {
        putenv('TRAIL_FIX=decoy');
        self::flightWithMinMax();
        $appended = Search::$lastPoolFixAppended;
        $this->assertNotEmpty($appended, 'decoy append обязан сработать (guard от no-op прогона)');
        $this->assertLessThanOrEqual(15, count($appended), 'decoy size-matched к pool-fix (≤C(6,2)=15)');
        foreach ($appended as $f) {
            $this->assertMatchesRegularExpression('/^\(x\d+(min|max)x\d+\)$/u', $f, 'decoy добавляет только min/max над raw-парами, got ' . $f);
        }
        $products = array_values(preg_grep('/×/', $appended) ?: []);
        $this->assertSame([], $products, 'decoy не добавляет произведения (специфичность контроля)');
    }
}
