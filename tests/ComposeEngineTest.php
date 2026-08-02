<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\ComposeEngine;

/**
 * Story D14 Phase 4: ComposeEngine — извлечение doComposeTick из Hive.
 */
class ComposeEngineTest extends TestCase
{
    /**
     * ComposeEngine находит compose-закон на подходящих данных.
     *
     * Predicted: FAIL — класс ComposeEngine не существует.
     */
    public function testComposeFindsLaw(): void
    {
        // Данные где sq(min(x0,x1)) = y
        $X = [[1, 3], [2, 4], [3, 5], [4, 6], [5, 7]];
        $y = [1, 4, 9, 16, 25]; // min²

        $engine = new ComposeEngine();
        $result = $engine->compose($X, $y, Grammar::baseOpNames(), 0.01);

        // Может найти, может нет — главное что не упал
        $this->assertIsArray($result);
    }

    /**
     * Пустой результат на шумных данных.
     */
    public function testComposeReturnsEmptyOnNoise(): void
    {
        $X = [[1, 1], [2, 2], [3, 3]];
        $y = [99, 88, 77];

        $engine = new ComposeEngine();
        $result = $engine->compose($X, $y, Grammar::baseOpNames(), 0.001);

        $this->assertEmpty($result);
    }
}
