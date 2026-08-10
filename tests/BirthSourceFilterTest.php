<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * BIRTH-SOURCE-FILTER (09.08, ЭКСП-022h): B-атомы рождаются из BASE-задач
 * (AND/ADD → +(max), +(add)) — тавтологичный мусор, засоряющий pool.
 * Рождение ТОЛЬКО из foraged/реальных задач. Base-домены: arithmetic, logic.
 */
class BirthSourceFilterTest extends TestCase
{
    private function invokeBirth(string $formula, string $domain): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'bsf_');
        $hive = new Hive(new \BeeSwarm\Infra\PlateauDetector(50, 0), null, maxTicks: 0, logFile: $logFile);
        $ref = new \ReflectionMethod(Hive::class, 'birthOperator');
        $ref->setAccessible(true);
        $ref->invoke($hive, $formula, $domain);
        $logFile = tempnam(sys_get_temp_dir(), 'bsf_');
        @unlink($logFile);
    }

    private function countBirths(): int
    {
        return (int) Database::get()->query(
            "SELECT COUNT(*) FROM grammar_ops WHERE source = 'birth'"
        )->fetchColumn();
    }

    public function testBaseDomainDoesNotGiveBirth(): void
    {
        $before = $this->countBirths();
        $this->invokeBirth('(x0addx1)', 'arithmetic');
        $this->invokeBirth('(x0addx1)', 'logic');
        $this->invokeBirth('(x0addx1)', 'dream');
        $this->assertSame($before, $this->countBirths(),
            'base/мусор-домены (arithmetic/logic/dream) не должны рождать B-атомы');
    }

    /**
     * Табличный тест по всему инвентарю доменов (deleg_1bf99a58 CONCERNS):
     * foraged_num/semantic/text + text → birth; arithmetic/logic/dream →
     * НЕТ. Единый источник правды: защита от добавления новых доменов.
     */
    public function testDomainInventoryTable(): void
    {
        $birth = ['foraged_num_abc', 'foraged_semantic_x', 'foraged_text_y', 'text'];
        $noBirth = ['arithmetic', 'logic', 'dream'];
        $before = $this->countBirths();
        foreach ($birth as $d) {
            $this->invokeBirth('(x0addx1)', $d);
        }
        $this->assertSame($before + count($birth), $this->countBirths(),
            'allow-list домены должны рожать: ' . json_encode($birth));
        foreach ($noBirth as $d) {
            $this->invokeBirth('(x0addx1)', $d);
        }
        $this->assertSame($before + count($birth), $this->countBirths(),
            'заблокированные домены НЕ должны рожать: ' . json_encode($noBirth));
    }
}
