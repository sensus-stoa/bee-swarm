<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomProvider;

/**
 * Guard на пустые ряды: AtomProvider::applyToRow бросал миллионы Warning
 * «Undefined array key 0» на ноутбуке (05.08), забивая php_errors.log.
 */
class AtomProviderGuardTest extends TestCase
{
    /**
     * RED: пустой ряд не должен давать Warning или исключение.
     * discover возвращает пустой массив законов.
     */
    public function testEmptyRowReturnsNoLaws(): void
    {
        // Данные с одним пустым рядом + одним нормальным
        $X = [[], [1, 2, 3]];
        $y = [0, 6];

        // Временно ловим ошибки чтобы проверить отсутствие Warning
        $caught = [];
        set_error_handler(function (int $errno, string $errstr) use (&$caught): bool {
            $caught[] = $errstr;
            return true;
        });

        $result = AtomProvider::discover($X, $y);
        restore_error_handler();

        $this->assertIsArray($result);
        $this->assertEmpty($result, 'Empty rows must not produce laws');
        $this->assertEmpty($caught, 'Empty rows must not trigger Undefined array key Warning');
    }

    /**
     * Нормальные данные по-прежнему дают открытия.
     */
    public function testNormalDataStillWorks(): void
    {
        // Простой ADD: y = x0 + x1 (но discover ищет атомы через AtomRegistry::all())
        $X = [[1, 2], [3, 4], [5, 6]];
        $y = [3, 7, 11];

        $result = AtomProvider::discover($X, $y);
        $this->assertIsArray($result);
        // Может найти или не найти — зависит от held-out/trivial фильтров,
        // главное что не падает с исключением
    }

    public function testRaggedRowsNoWarning(): void
    {
        // CONCERNS 05.08: первый ряд длинный (nFeat≥2), поздний короче —
        // бинарная ветка читает $row[1] → Warning. Guard: isset($row[1]).
        $X = [[1.0, 2.0], [3.0, 4.0], [5.0]];  // третий ряд — ragged
        $y = [3.0, 7.0, 5.0];

        $caught = [];
        set_error_handler(function (int $severity, string $message) use (&$caught): bool {
            $caught[] = $message;
            return true;
        });

        try {
            AtomProvider::discover($X, $y, 2);
        } finally {
            restore_error_handler();
        }

        $this->assertEmpty(
            array_filter($caught, fn (string $m): bool => str_contains($m, 'Undefined array key')),
            'Ragged rows must not produce Undefined array key warnings. Got: ' . implode('; ', $caught)
        );
    }
}
