<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

/**
 * REUSE-TOUCH-ATOM фаза 3 (10.08): подстрочный матчинг (definition-
 * подстрока) УДАЛЁН — touchAtom в точке применения достовернее
 * (подстрока ≠ применение: 15 мест молчаливого отказа аудита
 * deleg_0518ec3b). Regex B\d+ остаётся как fallback для формул,
 * содержащих B-имена (compose-пути, где touchAtom ещё не дошёл).
 */
class ReuseTouchAtomFallbackTest extends TestCase
{
    public function testDefinitionSubstringNoLongerRegisters(): void
    {
        // Атом B6=(x0addx1) существует, но формула с РАЗВЁРНУТЫМ
        // определением ((x0+x1)×x2) НЕ должна регистрировать reuse
        // по подстроке (подстрока ≠ применение!)
        Grammar::staticAdd('B6', 'birth', '(x0addx1)', 'foraged_a');

        $hive = new \BeeSwarm\Hive\Hive(
            new \BeeSwarm\Infra\PlateauDetector(50, 0), null,
            maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'touch3_')
        );
        $ref = new \ReflectionMethod(\BeeSwarm\Hive\Hive::class, 'registerReuseOps');
        $ref->invoke($hive, '((x0+x1)×x2)', 'foraged_b');

        $stmt = Database::get()->prepare(
            'SELECT reuse_count, reuse_domains FROM grammar_ops WHERE name = ?'
        );
        $stmt->execute(['B6']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['reuse_count'],
            'definition-подстрока больше не регистрирует reuse');
        $this->assertSame('[]', (string) $row['reuse_domains']);
    }

    public function testBNameInFormulaStillRegistersFallback(): void
    {
        // Regex-fallback: формула с ЯВНЫМ B-именем (compose-путь!)
        // регистрирует reuse — механизм не потерян
        Grammar::staticAdd('B5', 'birth', '(x0subx1)', 'foraged_a');

        $hive = new \BeeSwarm\Hive\Hive(
            new \BeeSwarm\Infra\PlateauDetector(50, 0), null,
            maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'touch3_')
        );
        $ref = new \ReflectionMethod(\BeeSwarm\Hive\Hive::class, 'registerReuseOps');
        $ref->invoke($hive, '((x0B5x1)mulx2)', 'foraged_b');

        $stmt = Database::get()->prepare('SELECT reuse_count FROM grammar_ops WHERE name = ?');
        $stmt->execute(['B5']);
        $this->assertGreaterThanOrEqual(1, (int) $stmt->fetchColumn(),
            'B-имя в формуле (fallback) регистрирует reuse');
    }
}
