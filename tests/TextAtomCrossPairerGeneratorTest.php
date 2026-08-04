<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\TextAtomCrossPairer;

/**
 * Story S2.7: Lazy Cross-Pair Generator.
 *
 * TextAtomCrossPairer::crossPair() → \Generator вместо array.
 * TaskGenerator::generate() → bounded sample из генератора.
 */
class TextAtomCrossPairerGeneratorTest extends TestCase
{
    /**
     * RED: crossPair() должен возвращать \Generator, не array.
     */
    public function testCrossPairReturnsGenerator(): void
    {
        $atoms = [
            'preg_match(GI)' => [7.2, 6.8, 7.5],
            'preg_match(DQ)' => [6.0, 5.5, 6.2],
        ];

        $result = TextAtomCrossPairer::crossPair($atoms, 'text_pairs');

        $this->assertInstanceOf(\Generator::class, $result,
            'crossPair() must return Generator for lazy evaluation');
    }

    /**
     * RED: Generator должен yield'ить корректные задачи.
     */
    public function testCrossPairGeneratorYieldsCorrectTasks(): void
    {
        $atoms = [
            'GI' => [7.2, 8.1, 6.5, 7.0],
            'DQ' => [6.0, 5.5, 7.0, 5.8],
            'Sleep' => [5, 6, 4, 5],
        ];

        $generator = TextAtomCrossPairer::crossPair($atoms, 'test_metrics');

        // Собрать все задачи из генератора
        $tasks = iterator_to_array($generator, false);

        // 3 атома → 3×2 = 6 перестановок
        $this->assertCount(6, $tasks, '3 atoms → 6 cross-pair tasks');

        // Каждая задача должна иметь правильную структуру
        foreach ($tasks as $task) {
            $this->assertArrayHasKey('name', $task);
            $this->assertArrayHasKey('data', $task);
            $this->assertArrayHasKey('domain', $task);
            $this->assertSame('test_metrics', $task['domain']);
            $this->assertStringStartsWith('txt_pair_', $task['name']);
            $this->assertGreaterThanOrEqual(3, count($task['data']));
            $this->assertCount(2, $task['data'][0], 'Must be [feature, target]');
        }
    }

    /**
     * RED: Пустой генератор при <2 атомах.
     */
    public function testCrossPairGeneratorEmptyWithOneAtom(): void
    {
        $generator = TextAtomCrossPairer::crossPair(
            ['preg_match(GI)' => [7.2, 6.8, 7.5]],
            'text_pairs'
        );

        $tasks = iterator_to_array($generator, false);
        $this->assertEmpty($tasks, 'Need ≥2 atoms for cross-pairing');
    }

    /**
     * RED: Генератор не аллоцирует все задачи в память при создании.
     * Проверяем через memory_get_usage() до/после создания генератора.
     */
    public function testCrossPairGeneratorDoesNotPreallocate(): void
    {
        // Создаём 200 атомов × 100 значений = симуляция большого набора
        $atoms = [];
        for ($i = 0; $i < 200; $i++) {
            $atoms["atom_{$i}"] = array_fill(0, 100, (float) $i);
        }

        $memBefore = memory_get_usage(true);
        $generator = TextAtomCrossPairer::crossPair($atoms, 'big_test');
        $memAfterCreate = memory_get_usage(true);

        // Создание генератора НЕ должно аллоцировать N×M задач
        $delta = $memAfterCreate - $memBefore;
        $this->assertLessThan(
            10 * 1024 * 1024, // <10MB на создание генератора
            $delta,
            "Generator creation must not allocate all N×M tasks. Delta: {$delta} bytes"
        );

        // Собрать первые 100 задач — должно работать без OOM
        $count = 0;
        foreach ($generator as $task) {
            $this->assertIsArray($task);
            $count++;
            if ($count >= 100) break;
        }
        $this->assertSame(100, $count, 'Must yield at least 100 tasks from 200 atoms');
    }

    /**
     * RED: TaskGenerator::generate() должен ограничивать cross-pair до MAX_CROSS_PAIR.
     *
     * Проверяется косвенно: при большом количестве текст-атомов
     * generate() не должен возвращать > MAX_CROSS_PAIR cross-pair задач.
     */
    public function testTaskGeneratorBoundsCrossPairSample(): void
    {
        $tg = new \BeeSwarm\Hive\TaskGenerator();

        // Используем рефлексию чтобы получить MAX_CROSS_PAIR
        $ref = new \ReflectionClass($tg);
        $maxProp = $ref->getProperty('maxCrossPair');
        $maxCrossPair = $maxProp->getValue($tg);

        $this->assertGreaterThan(0, $maxCrossPair, 'MAX_CROSS_PAIR must be positive');
        $this->assertLessThanOrEqual(5000, $maxCrossPair, 'MAX_CROSS_PAIR should be bounded');

        // Создаём foraged_txt_* задачи: 100 атомов → 9900 потенциальных пар
        $foragedTasks = [];
        for ($i = 0; $i < 100; $i++) {
            $foragedTasks[] = [
                'name' => "foraged_txt_atom_{$i}",
                'data' => array_map(fn ($v) => [(float) $v], range(1, 20)),
                'domain' => 'test',
            ];
        }

        $tasks = $tg->generate($foragedTasks);

        // Считаем cross-pair задачи среди результата
        $crossCount = count(array_filter($tasks, fn ($t) => str_starts_with($t['name'] ?? '', 'txt_pair_')));
        $this->assertLessThanOrEqual(
            $maxCrossPair,
            $crossCount,
            "Cross-pair tasks ({$crossCount}) must not exceed MAX_CROSS_PAIR ({$maxCrossPair})"
        );
    }
}
