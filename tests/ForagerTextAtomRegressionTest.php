<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

/**
 * Regression test: не-numeric preg_match результаты должны
 * создавать foraged_txt_* задачи через подсчёт вхождений.
 *
 * Баг: preg_match без capturing groups возвращал [[]],
 * is_numeric([]) = false → foraged_txt_* не создавались → cross-pair не работал.
 *
 * Фикс: StreamingAccumulator теперь считает count($result) для
 * non-numeric результатов.
 */
class ForagerTextAtomRegressionTest extends TestCase
{
    /**
     * applyTextAtom для preg_match без capture groups возвращает [[]].
     * is_numeric([]) должен быть false — это ожидаемо.
     * Но StreamingAccumulator должен обрабатывать этот случай.
     */
    public function testPregMatchWithoutCaptureGroupsReturnsNonNumeric(): void
    {
        $result = AtomRegistry::applyTextAtom('preg_match', "акты тут и акты там", 'акты');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        // Без capturing groups — результат содержит пустые массивы
        $this->assertIsArray($result[0]);
        $this->assertFalse(is_numeric($result[0]), 'Empty array must not be numeric');
    }

    /**
     * applyTextAtom для preg_match С capturing groups возвращает числа.
     */
    public function testPregMatchWithCaptureGroupsReturnsNumeric(): void
    {
        $result = AtomRegistry::applyTextAtom('preg_match', "GI: 7.2\nDQ: 6.0", '(\d+\.?\d*)');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertIsArray($result[0]);
        $this->assertTrue(is_numeric($result[0][0] ?? null), 'Capture group must extract numbers');
        $this->assertEquals(7.2, (float) $result[0][0]);
    }

    /**
     * match_label извлекает числа после метки.
     */
    public function testMatchLabelExtractsNumbers(): void
    {
        $result = AtomRegistry::applyTextAtom('match_label', "GI: 7.2\nDQ: 6.0\nStress: 3", 'GI');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertTrue(is_numeric($result[0] ?? null));
        $this->assertEquals(7.2, (float) $result[0]);
    }

    /**
     * Симуляция логики StreamingAccumulator: non-numeric → count.
     */
    public function testNonNumericResultUsesCount(): void
    {
        // Симулируем 5 вхождений preg_match(акты) в одном файле
        $content = "акты акты акты акты акты";
        $result = AtomRegistry::applyTextAtom('preg_match', $content, 'акты');

        // Логика из StreamingAccumulator (после фикса):
        if (is_array($result) && ! empty($result)) {
            if (is_numeric($result[0] ?? null)) {
                $value = (float) $result[0];
            } else {
                $value = (float) count($result);
            }
        } else {
            $value = null;
        }

        $this->assertEquals(5.0, $value, 'Non-numeric result should use count: 5 occurrences');
    }

    /**
     * Симуляция с capturing groups: значения извлекаются как числа.
     */
    public function testNumericResultUsesDirectValue(): void
    {
        $content = "GI: 7.2\nGI: 8.1\nGI: 6.5";
        $result = AtomRegistry::applyTextAtom('match_label', $content, 'GI');

        if (is_array($result) && ! empty($result)) {
            if (is_numeric($result[0] ?? null)) {
                $value = (float) $result[0];
            } else {
                $value = (float) count($result);
            }
        } else {
            $value = null;
        }

        $this->assertEquals(7.2, $value, 'match_label should extract numeric value');
    }
}
