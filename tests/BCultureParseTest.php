<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Infra\Database;

/**
 * B-CULTURE-PARSE (26.08.2026, EXP-029):
 * evaluateFormula((x0B1x1)) возвращал NULL — парсер не знал B-имена,
 * definition() не вызывался для неизвестных атомов-строк.
 *
 * Фиксы: birthOpNames() в parse + evalNode fallback к definition()
 * + row=[l,r] маппинг аргументов.
 */
class BCultureParseTest extends TestCase
{
    private static string $tmpDb = '';

    public static function setUpBeforeClass(): void
    {
        // Файловая БД (birth-запись через staticAdd требует migrate)
        self::$tmpDb = tempnam(sys_get_temp_dir(), 'bcult_') . '.db';
        Database::setPath(self::$tmpDb);
        Database::get(); // migrate
        \BeeSwarm\Core\Grammar::staticAdd('BT', 'birth', '(x0−x1)', 'foraged_test');
    }

    public static function tearDownAfterClass(): void
    {
        Database::setPath(':memory:');
        if (self::$tmpDb !== '' && file_exists(self::$tmpDb)) {
            @unlink(self::$tmpDb);
        }
    }

    protected function setUp(): void
    {
        // REVIEW deleg_fe365da6: единая точка сброса static-кэшей evaluator'а
        ExpressionEvaluator::resetCaches();
        \BeeSwarm\Core\AtomRegistry::clearDefCache();
    }

    public function testEvaluateFormulaWithBornAtom(): void
    {
        // x1 BT x2 = x1 − x2 (definition (x0−x1), row=[l,r])
        $X = [[5.0, 300.0, 290.0], [2.0, 310.0, 280.0]];
        $pred = ExpressionEvaluator::evaluateFormula('(x1BTx2)', $X, []);
        $this->assertNotNull($pred, 'B-форма вычисляется (было NULL!)');
        $this->assertSame([10.0, 30.0], $pred);
    }

    public function testNestedChainWithBornAtom(): void
    {
        // ((x1BTx2)×x0) — цепочка через культурный атом
        $X = [[5.0, 300.0, 290.0], [2.0, 310.0, 280.0]];
        $pred = ExpressionEvaluator::evaluateFormula('((x1BTx2)×x0)', $X, []);
        $this->assertNotNull($pred);
        $this->assertEqualsWithDelta(50.0, $pred[0], 0.0001); // 10*5
        $this->assertEqualsWithDelta(60.0, $pred[1], 0.0001); // 30*2
    }
}
