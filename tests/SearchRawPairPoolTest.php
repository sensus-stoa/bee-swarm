<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * M1-EXT v2 WU-2 (10.10): raw-pair pool без среза.
 *
 * Корневая причина SUM-дефицита (дебаг проб 8-18):
 * l1Keys на 6 фичах = 5398 форм; pair-формы (x2×x3) на индексе ~596 —
 * немой срез array_slice(l1Keys,0,40) отрезает их по ПОРЯДКУ ГЕНЕРАЦИИ
 * (R/K-производные вклиниваются). Сумма двух произведений не строится
 * никогда — даже при beam=0.
 *
 * Критерий WU-2: pure raw×raw pair-формы не режутся срезом пула.
 * sum-of-two-products (y = x0·x1 + x2·x3, 4 фичи) ДОЛЖНА находиться
 * при beam=0 на depth-2.
 *
 * Проба-подтверждение механизма (10.10): raw-pair-only pool=532 →
 * found=true, cv=0.000000, 18.1s (физическая замена Search.php на ноуте,
 * restore сверен md5).
 *
 * ⚠️ WU-2 коммитится ВМЕСТЕ с WU-1 (multi-descriptor beam): без ограничения
 * сборки пар пространство квадратично (pool 600 → OOM 8G, проба 18-I).
 */
class SearchRawPairPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Изоляция меток (12.10, premortem): чужие метки того же
        // fingerprint-скоупа смещают writer-квоты и read-рейтинг.
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM trail_marks');
    }

    /**
     * Фикстура: 4 фичи, y = x0·x1 + x2·x3 (точно, без шума).
     * Значения в разумном диапазоне (без нулей — ratio-CV), seeded mt_srand.
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function makeSumTask(int $nRows = 240): array
    {
        mt_srand(7);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < 4; $j++) {
                $row[] = mt_rand() / mt_getrandmax() * 4.0 - 2.0;
            }
            $X[] = $row;
            $y[] = $row[0] * $row[1] + $row[2] * $row[3];
        }
        return [$X, $y];
    }

    public function testSumOfTwoProductsFindableAtDepth2(): void
    {
        [$X, $y] = self::makeSumTask();
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);

        // Verify-путь M1-EXT v2 (§0.6-канон): WU-2 raw-pair pool = verify-
        // величина default OFF, тест включаем явно (TrailMarksTest-паттерн).
        putenv('SEARCH_BEAM_K=0');
        putenv('SEARCH_RAWPAIR_POOL=1');
        putenv('SEARCH_NO_PREREG=1');
        try {
            $res = Search::find($X, $y, $g, 2, null, 0.2, 0.15, 120.0);
        } finally {
            putenv('SEARCH_BEAM_K=10');
            putenv('SEARCH_RAWPAIR_POOL=');
            putenv('SEARCH_NO_PREREG=');
        }

        $this->assertTrue($res[0], 'sum-of-two-products must be found at depth-2 with beam=0, diag=' . $res[4]);
        $this->assertSame('((x0×x1)+(x2×x3))', $res[2]);
        $this->assertLessThan(0.15, $res[1]);
    }
}
