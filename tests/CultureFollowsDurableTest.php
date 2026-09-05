<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\RecordKeeper;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * T5-post-3 (story theorem-level): культура следует за durable-знанием.
 *
 * Staleness-чек 05.09 обнаружил: culture-via-usage деградировала —
 * weightsFromDb() читает grammar_ops.usage_count, который НИКТО не
 * инкрементит (все ops = weight 1, weightedPick равномерен). Премортем-И3
 * (deleg_122a0816) предполагал weight=confirmed_count в capped(), но
 * staleness показал: capped() вообще не вызывается в проде.
 *
 * Правильное связывание: при ПОДТВЕРЖДЕНИИ закона (re-discovery на других
 * данных) операторы, входящие в его формулу, получают usage_count+1.
 * Оператор, который строит много durable-законов, набирает культурный вес —
 * weightedPick ведёт рой к строительным операторам (NO-REWARD-FOR-NONBUILDERS
 * на уровне культуры).
 *
 * И2 (observability): каждое подтверждение пишет CONFIRMED_POOL с размером
 * пула (transition-only по определению: confirm — редкое событие).
 */
final class CultureFollowsDurableTest extends TestCase
{
    private RecordKeeper $keeper;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->keeper = new RecordKeeper();
    }

    protected function tearDown(): void
    {
        Database::setPath(':memory:');
        Database::reset();
    }

    private function grammarOpUsage(string $op): int
    {
        $stmt = Database::get()->prepare(
            'SELECT usage_count FROM grammar_ops WHERE name = ?'
        );
        $stmt->execute([$op]);
        $v = $stmt->fetchColumn();

        return $v === false ? 0 : (int) $v;
    }

    private function seedGrammarOp(string $op): void
    {
        Database::get()->prepare(
            'INSERT OR IGNORE INTO grammar_ops (name, source, usage_count) VALUES (?, ?, 0)'
        )->execute([$op, 'test_culture']);
    }

    /** RED: подтверждение закона бустит usage_count его оператора. */
    public function testConfirmBoostsOperatorUsage(): void
    {
        $this->seedGrammarOp('×');
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't1', 'domain' => 'test_c', 'fingerprint' => 'fp_A'],
            'test_c'
        );
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't2', 'domain' => 'test_c', 'fingerprint' => 'fp_B'],
            'test_c'
        );
        // confirm сработал (fp_A → fp_B): оператор × получает +1
        self::assertSame(1, $this->grammarOpUsage('×'),
            'подтверждение закона обязано бустить его оператора');
    }

    /** RED: unconfirmed повтор НЕ бустит. */
    public function testUnconfirmedRepeatDoesNotBoost(): void
    {
        $this->seedGrammarOp('×');
        // 3 повтора на одном fingerprint — usage остаётся 0
        foreach (['t1', 't2', 't3'] as $n) {
            $this->keeper->record(
                ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
                ['name' => $n, 'domain' => 'test_c', 'fingerprint' => 'fp_A'],
                'test_c'
            );
        }
        self::assertSame(0, $this->grammarOpUsage('×'),
            'повтор на тех же данных не подтверждает и не бустит');
    }

    /** RED: каждый confirm инкрементит на 1 (graduated weight, премортем И3). */
    public function testMultipleConfirmsAccumulate(): void
    {
        $this->seedGrammarOp('×');
        // confirm ×3 (fp_A→B→C→D)
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't1', 'domain' => 'test_c', 'fingerprint' => 'fp_A'],
            'test_c'
        );
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't2', 'domain' => 'test_c', 'fingerprint' => 'fp_B'],
            'test_c'
        );
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't3', 'domain' => 'test_c', 'fingerprint' => 'fp_C'],
            'test_c'
        );
        $this->keeper->record(
            ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't4', 'domain' => 'test_c', 'fingerprint' => 'fp_D'],
            'test_c'
        );
        // 3 confirms (после первой записи)
        self::assertSame(3, $this->grammarOpUsage('×'),
            'вес оператора = число подтверждений (graduated, не бинарный)');
    }

    /** RED: Hive пишет CONFIRMED_POOL лог при подтверждении (И2 observability).
     *  Reflection recordDiscovery напрямую — doDiscoverTick требует живую пчелу
     *  (two-level-wiring), а лог не зависит от пчелы. */
    public function testConfirmedPoolLoggedOnConfirm(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hive_conf_');
        $hive = new \BeeSwarm\Hive\Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $method = new \ReflectionMethod(\BeeSwarm\Hive\Hive::class, 'recordDiscovery');
        $method->setAccessible(true);
        $foundAny = false;

        $d = ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'];
        // Первое открытие
        $method->invoke($hive, $d,
            ['name' => 'pool1', 'domain' => 'test_pool', 'fingerprint' => 'fp_1'],
            'test_pool', $foundAny);
        // Подтверждение (другой fingerprint)
        $method->invoke($hive, $d,
            ['name' => 'pool2', 'domain' => 'test_pool', 'fingerprint' => 'fp_2'],
            'test_pool', $foundAny);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);
        self::assertStringContainsString(
            'CONFIRMED_POOL',
            $log,
            'подтверждение обязано логировать размер confirmed-пула (И2)'
        );
    }

    /** Премортем И2: cap 50 предотвращает квадратичный отрыв базовых ops. */
    public function testBoostCappedAtMaxWeight(): void
    {
        $this->seedGrammarOp('×');
        // выставляем usage на максимум
        Database::get()->prepare(
            'UPDATE grammar_ops SET usage_count = 50 WHERE name = ?'
        )->execute(['×']);
        // ещё 3 confirm'а
        foreach (['fp_B', 'fp_C', 'fp_D'] as $i => $fp) {
            $this->keeper->record(
                ['atom' => '(x0×K2)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
                ['name' => 'c' . $i, 'domain' => 'test_cap', 'fingerprint' => $fp],
                'test_cap'
            );
        }
        self::assertSame(50, $this->grammarOpUsage('×'),
            'cap 50: базовые ops не могут уйти в бесконечность (ЭКСП-014)');
    }

    /** Boost только ops, присутствующим в грамматике (чужие токены молча игнор). */
    public function testBoostIgnoresUnknownTokens(): void
    {
        // формула с токеном 'zzz' (не op) — не должна ломать
        $this->keeper->record(
            ['atom' => '(x0×zzz)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't1', 'domain' => 'test_c2', 'fingerprint' => 'fp_A'],
            'test_c2'
        );
        $this->keeper->record(
            ['atom' => '(x0×zzz)', 'cv' => 0.01, 'class' => 'EMPIRICAL'],
            ['name' => 't2', 'domain' => 'test_c2', 'fingerprint' => 'fp_B'],
            'test_c2'
        );
        // 'zzz' игнорируется (не op), но '×' в формуле ЕСТЬ → бустится (создан с 1)
        $this->assertSame(1, $this->grammarOpUsage('×'),
            'реальный оп из формулы бустится даже рядом с чужим токеном');
    }
}
