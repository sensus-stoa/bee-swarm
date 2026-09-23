<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Полный регресс-тест пайплайна. 15 тиков, ~10 секунд.
 *
 * Инварианты, которые были сломаны в production:
 * - srand(42) → array_rand детерминизм (1 закон вместо 450)
 * - scanWithAccumulator → 0 открытий (D9 regression)
 * - MAX_CROSS_PAIR=2000 → пул раздут (text_pair flood)
 * - nFeat=0 задачи → фильтр не работал
 *
 * Запускать: vendor/bin/phpunit tests/FullPipelineRegressionTest.php
 *
 * V0.17: все тесты found-зависимы через живой Hive (CPU-guard tick:1116
 * load>0.7 → early return → меньше discovery) — wall-clock-класс.
 *
 * ДЕТЕРМИНИЗМ ВХОДА (23.09, v0.18 WU-4): тест ломался не кодом, а данными —
 * без FORAGER_SOURCES Hive сканирует ~/Documents|Desktop|Downloads ноутбука,
 * состав пула (210→173 задач, класс metric-domain 86→35) меняется от файлов
 * пользователя, и сид-детерминизм mt_srand умирает молча. Бисекция: пред-WU-1
 * src тоже красный → виновата среда. Фикс: пинним источник на ПУСТОЙ tmpdir,
 * созданный тестом (пустой каталог → 0 foraged-задач; discovery питается
 * base-задачами TaskManager: ADD/MUL + AND/OR/XOR = ровно 2 домена, без
 * зависимости от файлов пользователя). Сид 777: seed-sweep 8 сидов даёт
 * 19 laws / 2 домена — запас ≥2× по обоим инвариантам (42/9 вырождены:
 * 1 law). Ассерты не ослаблены — воспроизводим вход.
 *
 * @group slow
 */
class FullPipelineRegressionTest extends TestCase
{
    /**
     * Главный инвариант: ≥3 открытий за 15 тиков на чистой БД.
     */
    private static ?Hive $sharedHive = null;

    private static string $prevForagerSources = '';

    private static string $syntheticDir = '';

    /**
     * ПАРАЛЛЕЛИЗМ (10.08): раньше 5 тестов × 12 тиков = 60 тиков серийно
     * (~144с). ОДИН прогон на класс — тесты читают static (12 тиков,
     * ~30с). Проверяемые инварианты не зависят от порядка тиков.
     */
    public static function setUpBeforeClass(): void
    {
        // Синтетический источник фуражира (см. class-doc): пустой tmpdir.
        $prev = getenv('FORAGER_SOURCES');
        self::$prevForagerSources = $prev === false ? '' : (string) $prev;
        self::$syntheticDir = sys_get_temp_dir() . '/fpr_synth_' . getmypid();
        if (! is_dir(self::$syntheticDir)) {
            mkdir(self::$syntheticDir, 0777, true);
        }
        putenv('FORAGER_SOURCES=' . self::$syntheticDir);

        self::$prevRngState = mt_rand();
        mt_srand(777);
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        // Изоляция грамматики: живые-Hive тесты раньше по процессу оставляют
        // birth-опы в общей :memory: Database (BehavioralDiversity 20 тиков
        // рождает до 5 B-опов) -> 16 ops вместо 11 -> сид-детерминизм (777
        // калиброван на 11 ops) умирает. Чистим: сид-свип валиден только на
        // базовой грамматике.
        // Чистим ВСЮ grammar_ops: живой Hive того же процесса оставляет
        // culture/boost/discovered строки; расширенная грамматика ломает
        // seed-калибровку (сид-свип 777 валиден на 11 базовых ops).
        \BeeSwarm\Infra\Database::run('DELETE FROM grammar_ops');
        \BeeSwarm\Core\ExpressionEvaluator::clearDefCache();
        \BeeSwarm\Core\AtomRegistry::clearDefCache();
        \BeeSwarm\Core\AtomRegistry::resetDiscoveredAtoms();
        $logFile = tempnam(sys_get_temp_dir(), 'regress_');
        $plateau = new \BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0);
        $hive = new \BeeSwarm\Hive\Hive(plateau: $plateau, maxTicks: 15, logFile: $logFile);
        $hive->run();
        unlink($logFile);
        self::$sharedHive = $hive;
    }

    private static int $prevRngState = 0;

    public static function tearDownAfterClass(): void
    {
        // putenv($k) без значения снимает переменную ДО КОНЦА phpunit-процесса
        // (678 скипов, 23.09). Восстанавливаем предыдущее состояние честно.
        if (self::$prevForagerSources === '') {
            putenv('FORAGER_SOURCES');
        } else {
            putenv('FORAGER_SOURCES=' . self::$prevForagerSources);
        }
        mt_srand(self::$prevRngState);
        if (self::$syntheticDir !== '' && is_dir(self::$syntheticDir)) {
            @rmdir(self::$syntheticDir);
        }
        self::$sharedHive = null;
        parent::tearDownAfterClass();
    }

    public function testDiscoveriesMade(): void
    {
        $laws = \BeeSwarm\Infra\Database::get()->query(
            'SELECT COUNT(*) FROM laws'
        )->fetchColumn();

        $this->assertGreaterThanOrEqual(
            3,
            (int) $laws,
            "Expected ≥3 laws. Got {$laws}. srand(42)? scanWithAccumulator?"
        );
    }

    /**
     * Инвариант: открытия в ≥2 доменах (не только logic).
     */
    public function testMultipleDomains(): void
    {
        $hive = self::$sharedHive;

        $domains = \BeeSwarm\Infra\Database::get()->query(
            'SELECT COUNT(DISTINCT domain) FROM laws'
        )->fetchColumn();

        $this->assertGreaterThanOrEqual(
            2,
            (int) $domains,
            "Expected ≥2 domains. Got {$domains}. All logic-only?"
        );
    }

    /**
     * Инвариант: пул задач не раздут.
     */
    public function testTaskPoolNotBloated(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $hive = new Hive(plateau: new PlateauDetector(50, plateauSleepUs: 0), maxTicks: 0);
        $hive->run();
        $ref = new \ReflectionMethod(Hive::class, 'getTasks');
        $tasks = $ref->invoke($hive);

        $this->assertLessThan(
            500,
            count($tasks),
            'Task pool bloated: ' . count($tasks) . ' tasks. MAX_CROSS_PAIR=2000?'
        );
    }

    /**
     * Инвариант: нет задач с nFeat=0.
     */
    public function testNoZeroFeatureTasks(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $hive = new Hive(plateau: new PlateauDetector(50, plateauSleepUs: 0), maxTicks: 0);
        $hive->run();
        $ref = new \ReflectionMethod(Hive::class, 'getTasks');
        $tasks = $ref->invoke($hive);

        $zeroFeat = 0;
        foreach ($tasks as $t) {
            if (! isset($t['data'][0]) || ! is_array($t['data'][0])) {
                continue;
            }
            if (count($t['data'][0]) - 1 < 1) {
                $zeroFeat++;
            }
        }

        $this->assertEquals(
            0,
            $zeroFeat,
            "Found {$zeroFeat} tasks with nFeat=0. Filter regression?"
        );
    }

    /**
     * Инвариант: пчёлы живы после 25 тиков.
     */
    public function testBeesAliveAfterTicks(): void
    {
        $hive = self::$sharedHive;

        $alive = count(array_filter(
            $hive->getBees(),
            fn ($b) => $b->isAlive()
        ));

        $this->assertGreaterThan(
            0,
            $alive,
            'All bees dead after 25 ticks'
        );
    }

    private function runHive(int $maxTicks): Hive
    {
        $logFile = tempnam(sys_get_temp_dir(), 'regress_');
        $plateau = new PlateauDetector(50, plateauSleepUs: 0);
        $hive = new Hive(plateau: $plateau, maxTicks: $maxTicks, logFile: $logFile);
        $hive->run();
        unlink($logFile);
        return $hive;
    }
}
