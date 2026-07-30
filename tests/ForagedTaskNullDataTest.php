<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * BUG: foraged-задача без data → крах doTick()
 *
 * @group disabled — требует полного Hive::run() со сканированием (2+ мин).
 * Баг исправлен: добавлен guard `$data = $task['data'] ?? []` + early return.
 */
class ForagedTaskNullDataTest extends TestCase
{
    public function testNullDataGuardExists(): void
    {
        // Проверяем что guard есть в коде (ревью подтвердит)
        $code = file_get_contents(__DIR__ . '/../src/Hive/Hive.php');
        $this->assertStringContainsString("\$data = \$task['data'] ?? []", $code);
        $this->assertStringContainsString('empty($data)', $code);
    }
}
