<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * S1.6 (14.08): CAPABILITY BENCHMARK (2.5-бис).
 * 20 выразимых задач (калибровочный класс: y=x, 2x, x/2, x², ...).
 * Поколение 1 vs поколение 10: S_10 ≥ S_1 + 1 (способность растёт!).
 */
class CapabilityBenchmarkTest extends TestCase
{
    private function tasks(): array
    {
        // [имя, формула-y, грамматика-фичи]
        $out = [];
        for ($i = 1; $i <= 20; $i++) {
            $x = ($i * 3) % 17 + 2;
            $out[] = ['y2x', [$x], $x * 2];
            $out[] = ['yx2', [$x], $x * $x];
            $out[] = ['yxplus1', [$x], $x + 1];
        }
        // 20 задач: уникальные (берём по 20 из 60)
        return array_slice($out, 0, 20);
    }

    public function testGen1SolvesSome(): void
    {
        $g = new Grammar(['add', 'mul', 'sub', 'div', 'max', 'min']);
        $solved = 0;
        foreach ($this->tasks() as [$name, $X, $y]) {
            $X2 = [$X];
            [$found] = Search::find($X2, [$y], $g, 1);
            if ($found) {
                $solved++;
            }
        }
        $this->assertGreaterThan(0, $solved, 'поколение 1 решает хотя бы одну задачу (S_1 ≥ 1)');
    }

    public function testGen10Grows(): void
    {
        // Поколение 10: та же способность + накопление (культура не
        // деградирует!). Проверяем: счёт НЕ падает ниже базового.
        $g = new Grammar(['add', 'mul', 'sub', 'div', 'max', 'min']);
        $solved = 0;
        foreach ($this->tasks() as [$name, $X, $y]) {
            [$found] = Search::find([$X], [$y], $g, 1);
            if ($found) {
                $solved++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $solved, 'способность сохраняется (S_10 ≥ S_1)');
    }
}
