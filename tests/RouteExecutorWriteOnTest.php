<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

/**
 * EXP-045 v1.1 write-on (dev_run1 причина 1, REPORT.md §3.1).
 *
 * Дефект (RED): RouteExecutor::flight включал TRAIL_MARKS только при
 * marksK>0 (после USE_MARKS) — маршрут [FLIGHT, USE_MARKS, FLIGHT] не
 * накапливал дорогу: первый вылет не писал (флаг снят целиком), третий
 * читал пустую таблицу. Дорожная память EXP-040 в маршрутах не
 * разворачивалась; evolved = random = shuffled в dev_run1.
 *
 * Контракт v1.1: write в route-канале ВСЕГДА on (БД руки изолирована,
 * task-start DELETE в драйвере driver.php:277), USE_MARKS управляет
 * ТОЛЬКО чтением. TRAIL_READ_K='0' читает честный ноль — Search
 * различает '0' и unset (старый `?: '5'` трактовал '0' как unset:
 * falsy в PHP, тихий fallback на 5).
 */
class RouteExecutorWriteOnTest extends TestCase
{
    private const LABELS = ['x0', 'x1', 'x2', 'x3', 'x4', 'x5'];

    /**
     * dot-фикстура (TrailMarksTest.makeDotTask): полный закон =
     * сумма ТРЁХ произведений — на depth-2 за один вылет недостижим,
     * частичные суммы дают сигнал (cv 1.7-3.6 против null ~4).
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function dotTask(int $seed, int $nRows = 200): array
    {
        mt_srand($seed);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < 6; $j++) {
                $row[] = mt_rand() / mt_getrandmax() * 4.0 - 2.0;
            }
            $X[] = $row;
            $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
        }

        return [$X, $y];
    }

    /**
     * Тот же расчёт scope, что в Search::writeTrailMarks: ТОЛЬКО labels.
     */
    private static function scopeKey(): string
    {
        return 'sha1:' . sha1(json_encode([
            'labels' => self::LABELS,
        ]));
    }

    private static function markCount(): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM trail_marks WHERE fingerprint = ?'
        );
        $stmt->execute([self::scopeKey()]);

        return (int) $stmt->fetchColumn();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec('DELETE FROM trail_marks');
        // Ambient-гигиена драйвера (driver.php:176,184): флагов НЕТ —
        // маршрут обязан включать запись сам (write-on), не наследовать.
        putenv('TRAIL_MARKS');
        putenv('TRAIL_READ_K');
        putenv('SEARCH_RAWPAIR_POOL');
        putenv('SEARCH_BEAM_K');
        putenv('L2_BEAM_K');
    }

    protected function tearDown(): void
    {
        // unset, не '=': пустая строка != unset. Сегодня гейты ==='1' и
        // Search-трактовка ''→unset-эквивалент делают '' benign, но любой
        // consumer, различающий false-vs-'' (прецедент EMPTY_POOL_SLEEP_US,
        // где '' → 0), молча разойдётся (ревью deleg_f043f6e1, LOW).
        putenv('TRAIL_MARKS');
        putenv('TRAIL_READ_K');
        putenv('SEARCH_RAWPAIR_POOL');
        putenv('SEARCH_BEAM_K');
        putenv('L2_BEAM_K');
        Database::get()->exec('DELETE FROM trail_marks');
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function plainFlightGenome(): array
    {
        return [
            'id' => 'route_writeon01',
            'condition' => [
                'kind' => 'ALWAYS',
            ],
            'budget_fraction' => 0.1,
            'max_steps' => 1,
            'actions' => [
                [
                    'op' => 'FLIGHT',
                    'grammar' => 'base',
                    'beam' => 'default',
                    'depth' => 2,
                ],
            ],
        ];
    }

    /**
     * ЯДРО v1.1: обычный FLIGHT (без USE_MARKS в маршруте) ОБЯЗАН писать
     * метки. До фикса — 0 строк: TRAIL_MARKS снимался целиком, write-путь
     * Search (TRAIL_MARKS==='1') был мёртв на всех вылетах до USE_MARKS.
     */
    public function testPlainFlightWritesTrailMarksWithoutUseMarks(): void
    {
        [$X, $y] = self::dotTask(42);
        $res = RouteExecutor::run(self::plainFlightGenome(), $X, $y, self::LABELS, 30.0, 0.10);

        $this->assertFalse($res['found'], 'pre-condition: dot is not solvable at depth-2 in one flight');
        $this->assertGreaterThan(
            0,
            self::markCount(),
            'plain FLIGHT (no USE_MARKS) must WRITE trail marks (v1.1 write-on); got 0'
        );
    }

    /**
     * Контракт ручек: write всегда '1'; USE_MARKS управляет ТОЛЬКО чтением
     * через TRAIL_READ_K; '0' — честный ноль чтения (не unset).
     */
    public function testMarksKnobsUseMarksControlsReadOnly(): void
    {
        $this->assertSame(
            [
                'TRAIL_MARKS' => '1',
                'TRAIL_READ_K' => '0',
            ],
            RouteExecutor::marksKnobs(0),
            'no USE_MARKS: write on, read honestly zero'
        );
        $this->assertSame(
            [
                'TRAIL_MARKS' => '1',
                'TRAIL_READ_K' => '3',
            ],
            RouteExecutor::marksKnobs(3),
            'USE_MARKS k=3: write on, read top-3'
        );
    }

    /**
     * Пин фиксации fallback в Search: TRAIL_READ_K='0' при beam>0 обязан
     * читать НОЛЬ меток (LIMIT 0), а не fallback-5. Seed = частичная сумма:
     * при чтении метка⊕(x2×x5) = полный dot-закон (ядро лестницы EXP-040),
     * find() находит; при честном нуле чтения закон недостижим на depth-2.
     */
    public function testSearchReadKZeroIsHonestZero(): void
    {
        [$X, $y] = self::dotTask(42);
        Database::get()->prepare(
            'INSERT INTO trail_marks (fingerprint, formula) VALUES (?, ?)'
        )->execute([self::scopeKey(), '((x0×x3)+(x1×x4))']);

        putenv('TRAIL_MARKS=1');
        putenv('TRAIL_READ_K=0');
        putenv('SEARCH_BEAM_K=10');
        putenv('L2_BEAM_K=40');
        putenv('SEARCH_RAWPAIR_POOL=1');
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq', 'sqrt']);

        $res = Search::find($X, $y, $g, 2, self::LABELS, 0.2, 0.15, 90.0);

        $this->assertFalse(
            $res[0],
            "TRAIL_READ_K='0' must read NOTHING ('0' was falsy-falling back to 5); "
            . 'seeded mark must NOT reach the pool'
        );
    }
}
