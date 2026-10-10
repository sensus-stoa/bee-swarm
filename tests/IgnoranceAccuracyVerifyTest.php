<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\IgnoranceAccuracyVerify;
use PHPUnit\Framework\TestCase;

/**
 * S2.3-IGNORANCE: verify_2_3 — измерение гейта §3.3 («категория верна чаще,
 * чем случайный угадыватель»: ≥7/10 на контрольном наборе, ≥2 категории).
 *
 * Полный прогон E2E-дорог (~6s, prereg 10.10); юнит-тесты покрывают КОНТРАКТ
 * класса: гвард персистентной БД, форму вердикта, гейт-логику вердикта.
 * PASS-вердикт полного прогона верифицируется CLI-прогоном (FormInvarianceVerifyTest
 * прецедент: один полный E2E на сторю, не на тест).
 */
final class IgnoranceAccuracyVerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IgnoranceAccuracyVerify::reset();
    }

    protected function tearDown(): void
    {
        putenv('V23_VERIFY_OK');
        putenv('SWARM_DB_PATH=:memory:');
        // testReportShape ставит NO_BIRTH=1 — снять (cross-test pollution:
        // NO_BIRTH глушит bornBinary-чтение Search → V28-тесты соседнего
        // воркера теряют атом-контраст; поймано бисекцией 10.10)
        putenv('NO_BIRTH');
        putenv('SEARCH_NO_PREREG');
        IgnoranceAccuracyVerify::reset();
        parent::tearDown();
    }

    /**
     * Гвард: персистентная БД без opt-in = отказ (Search пишет
     * preregistrations в живую таблицу; прецедент V22B/V28).
     */
    public function testPersistentDbRefusedWithoutOptIn(): void
    {
        putenv('SWARM_DB_PATH=/tmp/fake_persistent_v23.sqlite');
        putenv('V23_VERIFY_OK');
        $this->expectException(\RuntimeException::class);
        IgnoranceAccuracyVerify::report();
    }

    /**
     * Opt-in проходит гвард (reflection на guard — полный прогон не гнать).
     */
    public function testPersistentDbAllowedWithOptIn(): void
    {
        putenv('SWARM_DB_PATH=/tmp/fake_persistent_v23.sqlite');
        putenv('V23_VERIFY_OK=1');
        $m = new \ReflectionMethod(IgnoranceAccuracyVerify::class, 'assertDatabaseIsDisposable');
        $m->setAccessible(true);
        $m->invoke(null);
        $this->assertTrue(true);
    }

    /**
     * Вердикт-гейт: score < 7 → pass=false при любых категориях.
     */
    public function testVerdictGateBelowSeven(): void
    {
        $m = new \ReflectionMethod(IgnoranceAccuracyVerify::class, 'verdict');
        $m->setAccessible(true);

        $below = $m->invoke(null, 6, ['DATA', 'NOISE']);
        $this->assertFalse($below, '6/10 < 7 → отказ');

        // 7/10 но одна категория → отказ (угадыватель одной категории не 25%)
        $mono = $m->invoke(null, 7, ['GRAMMAR']);
        $this->assertFalse($mono, '7/10 при 1 категории → отказ');

        $pass = $m->invoke(null, 7, ['DATA', 'NOISE']);
        $this->assertTrue($pass, '7/10 при 2+ категориях → гейт §3.3 выполнен');
    }

    /**
     * Форма отчёта (полный прогон в :memory: — ~6s, допустимо в юнит-тесте
     * по прецеденту EnvPressureVerify; гвард + shape в одном прогоне).
     */
    public function testReportShape(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('NO_BIRTH=1');
        $r = IgnoranceAccuracyVerify::report();
        foreach (['pass', 'score', 'cases', 'kinds', 'pending', 'env'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertIsBool($r['pass']);
        $this->assertSame(':memory:', $r['env']['SWARM_DB_PATH']);
        $this->assertCount(10, $r['cases'], 'контрольный набор = 10 кейсов');
    }
}
