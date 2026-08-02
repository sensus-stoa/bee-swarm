<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;

/**
 * Story E1-FIX Phase 2: Text atom bootstrap.
 *
 * Корпус загружен (5000 слов). Надо чтобы Hive обнаруживал
 * текстовые атомы из контента задач и они становились доступны
 * для Forager.
 */
class TextAtomBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \BeeSwarm\Infra\Database::get()->exec(
            "DELETE FROM grammar_ops WHERE source IN ('test','discovered')"
        );
    }

    /**
     * AtomRegistry::applyTextAtom извлекает значения из контента.
     *
     * Predicted: PASS — applyTextAtom уже существует.
     */
    public function testMatchLabelExtractsValue(): void
    {
        $content = "GI: 7.2\nDQ: 6.0\nSleep: 5.5";
        $result = AtomRegistry::applyTextAtom('match_label', $content, 'GI');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertEquals(7.2, $result[0]);
    }

    /**
     * preg_match извлекает паттерны из текста.
     *
     * Predicted: PASS — applyTextAtom уже существует.
     */
    public function testPregMatchExtractsPattern(): void
    {
        $content = "Изоляция: 3 дня\nБездействие: 5 часов";
        // preg_match ожидает regex с capture groups
        $result = AtomRegistry::applyTextAtom('preg_match', $content, '(\\d+)');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        // Должен найти числа 3 и 5
        $this->assertContains('3', array_merge(...$result));
        $this->assertContains('5', array_merge(...$result));
    }

    /**
     * Text atom discovery через addDiscoveredTextAtom → появляется в grammar_ops.
     *
     * Predicted: PASS — addDiscoveredTextAtom уже существует.
     */
    public function testDiscoveredTextAtomAppearsInGrammarOps(): void
    {
        AtomRegistry::addDiscoveredTextAtom('preg_match', 'TestLabel');

        $all = (new Grammar())->all();
        $this->assertContains('preg_match(TestLabel)', $all, 'Discovered text atom must be in grammar');
    }
}
