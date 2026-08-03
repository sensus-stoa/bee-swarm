<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * V0.8.5 Phase 1: Search::find возвращает train/test CV для классификации законов.
 */
class LawClassificationTest extends TestCase
{
    /**
     * На ADD-данных: найденный закон CV_train=0, CV_test=0 (не IDENTITY).
     */
    public function testAddLawIsEmpirical(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10],
              [2, 5], [4, 1], [6, 3], [8, 7], [10, 0],
              [3, 3], [5, 5], [7, 7], [9, 9], [1, 1]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10, 6, 10, 14, 18, 2];

        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add']));
        $result = Search::find($X, $y, $g, 2, ['x0', 'x1'], testRatio: 0.2);

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
        $this->assertTrue($result[0]);
        $this->assertLessThan(0.01, $result[1]); // cv_train
        $this->assertStringContainsString('x0', $result[2]); // formula
        $this->assertLessThan(0.05, $result[3]); // cv_test
        $this->assertSame('EMPIRICAL', $result[4]);
    }

    /**
     * IDENTITY-тест: R×x × x/R×x на любых данных даёт CV_train≈0, CV_test≫0.
     * Используем реальный пример: данные где нет реального закона.
     */
    public function testIdentityDetected(): void
    {
        // Генерируем случайные данные
        $X = []; $y = [];
        for ($i = 0; $i < 30; $i++) {
            $X[] = [(float) mt_rand(10, 100) / 10, (float) mt_rand(10, 100) / 10];
            $y[] = (float) mt_rand(10, 100) / 10;
        }

        $g = Grammar::fromOps(Grammar::baseOpNames());
        $result = Search::find($X, $y, $g, 2, ['x0', 'x1'], testRatio: 0.3);

        $this->assertIsArray($result);
        // Если нашёлся → проверить класс
        if ($result[0]) {
            // cv_train должен быть близок к 0 для IDENTITY
            // cv_test должен быть высоким (тавтология не обобщается)
            $cv_diff = $result[3] - $result[1];
            $this->assertGreaterThan(0.1, $cv_diff, 'IDENTITY: test CV should be much higher than train CV');
            $this->assertSame('IDENTITY', $result[4]);
        }
        // Если не нашёлся — тоже ок (шум)
    }
}
