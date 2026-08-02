<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\ClozeEngine;

/**
 * Story D14 Phase 5: ClozeEngine — извлечение doClozeTick из Hive.
 */
class ClozeEngineTest extends TestCase
{
    /**
     * ClozeEngine не падает на пустых данных.
     *
     * Predicted: FAIL — класс ClozeEngine не существует.
     */
    public function testClozeHandlesEmptyInput(): void
    {
        $engine = new ClozeEngine();
        $result = $engine->findBestAtom([], []);

        $this->assertNull($result);
    }

    /**
     * ClozeEngine возвращает null без sentence registry.
     */
    public function testClozeReturnsNullWithoutRegistry(): void
    {
        $engine = new ClozeEngine();
        $result = $engine->findBestAtom(
            [[1, 2, 3, 1.0]],
            ['add', 'mul', 'sub']
        );

        // Без SentenceRegistry — не может работать
        $this->assertNull($result);
    }
}
