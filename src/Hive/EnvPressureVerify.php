<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\CarryingCapacity;

/**
 * §2.6 Measurement: verify_1_6.
 *
 * Проверяет на логе демона: (a) >=1 задача выброшена TIMEOUT
 * (MISSED_OPPORTUNITY), (b) сложность задач менялась >=1 раз (ENV_DIFF),
 * (c) carrying capacity N_max/N_median <= 3 (GEN pop=, экстинкции N=0
 * исключены). Pass: все три условия.
 *
 * Логика отделена от CLI-скрипта (scripts/verify/verify_1_6.php) —
 * тестируется юнит-тестами, скрипт — тонкая обёртка.
 */
final class EnvPressureVerify
{
    /**
     * @return array{pass: bool, missed: int, diffChanges: int, cc: array<string, mixed>, generations: int, pending: list<string>}
     */
    public static function run(string $logContent): array
    {
        [$missed, $diffEvents, $pops] = self::parse($logContent);
        $cc = CarryingCapacity::analyze($pops);

        $allPass = $missed >= 1
            && count($diffEvents) >= 1
            && $cc['pass'] === true;

        $pending = [];
        if (count($pops) < 2) {
            $pending[] = 'need GEN events for carrying capacity';
        }
        if ($missed === 0) {
            $pending[] = 'no MISSED_OPPORTUNITY yet (K-tick window too short?)';
        }
        if (count($diffEvents) === 0) {
            $pending[] = 'no ENV_DIFF events yet (solve-rate never crossed 90/10 gates)';
        }

        return [
            'pass' => $allPass,
            'missed' => $missed,
            'diffChanges' => count($diffEvents),
            'cc' => $cc,
            'generations' => count($pops),
            'pending' => $pending,
        ];
    }

    /**
     * @return array{0: int, 1: list<array{dir: string, level: int}>, 2: list<int>}
     */
    private static function parse(string $logContent): array
    {
        $missed = 0;
        $diffEvents = [];
        $pops = [];

        foreach (preg_split('/\R/', $logContent) ?: [] as $line) {
            if (str_contains($line, 'MISSED_OPPORTUNITY:')) {
                $missed++;
            }
            // ENV_DIFF: rise|fall level=N
            if (preg_match('/ENV_DIFF: (rise|fall) level=(\d+)/', $line, $m) === 1) {
                $diffEvents[] = [
                    'dir' => $m[1],
                    'level' => (int) $m[2],
                ];
            }
            // GEN: 5 pop=12 unique=3 diversity=0.5 avg|G|=8
            if (preg_match('/GEN:\s+\d+\s+pop=(\d+)/', $line, $m) === 1) {
                $pops[] = (int) $m[1];
            }
        }

        return [$missed, $diffEvents, $pops];
    }
}
