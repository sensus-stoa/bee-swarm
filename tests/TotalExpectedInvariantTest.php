<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Инвариант-гейт для run_two_pass.sh: TOTAL_EXPECTED должен равняться
 * фактическому числу объявленных тестов (--list-tests). Прецедент 29.09:
 * константа 1133 (25.09) отстала — 3 env-skips 25.09 выполняются сейчас +
 4 новых DreamCongruence → фактический total 1140. Прогон зелёный
 * (fast OK 1131 + slow OK 9), инвариант NO — ложная тревога от stale-константы.
 *
 * Тест держит раскладку под контролем: падает, если fast+slow != list-tests,
 * т.е. кто-то добавил тесты и не поднял TOTAL_EXPECTED.
 */
final class TotalExpectedInvariantTest extends TestCase
{
    public function testTotalExpectedMatchesDeclaredTestCount(): void
    {
        $script = dirname(__DIR__) . '/scripts/run_two_pass.sh';
        self::assertFileExists($script);
        $src = (string) file_get_contents($script);
        self::assertSame(
            1,
            preg_match('/^TOTAL_EXPECTED=(\d+)$/m', $src, $m),
            'TOTAL_EXPECTED объявлен в run_two_pass.sh'
        );
        $expected = (int) $m[1];

        // Фактическое число объявленных тестов (включая slow-группу)
        $list = shell_exec(
            'php ' . escapeshellarg(dirname(__DIR__) . '/vendor/bin/phpunit')
            . ' ' . escapeshellarg(dirname(__DIR__) . '/tests')
            . ' --list-tests 2>/dev/null | grep -c " - "'
        );
        $declared = (int) trim((string) $list);
        self::assertGreaterThan(0, $declared, 'list-tests вернул счётчик');
        self::assertSame(
            $declared,
            $expected,
            "TOTAL_EXPECTED={$expected} != объявленных тестов {$declared} — подними константу при добавлении тестов"
        );
    }
}
