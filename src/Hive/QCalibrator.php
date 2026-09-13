<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * V0.14 WU-4 (verification-economy): q-калибровка консенсуса.
 *
 * Спека: «q = ceil(θ · median(подтверждений на нуллах) + 1)», θ из env.
 * Нулл-базовая линия домена = confirmed_count всех законов домена (кандидат
 * обязан превысить средний уровень подтверждаемости домена). Noise-домен:
 * медиана 0 → q=1; воспроизводимый шум (ложные законы набирают подтверждения)
 * тянет порог выше; точный домен с 5/5 → медиана может быть > 0, но cap 5
 * (спека: q ≤ 5).
 *
 * Cap сверху RESAMPLE_COUNT: порог не выше полного консенсуса (спека: q ≤ 5).
 */
final class QCalibrator
{
    /**
     * θ из env Q_THETA: множитель медианы нуллов.
     */
    public const DEFAULT_THETA = 1.0;

    /**
     * Максимальный q: полный консенсус resample (спека: q ≤ 5).
     */
    public const Q_CAP = 5;

    public function qForDomain(string $domain): int
    {
        $thetaRaw = getenv('Q_THETA');
        $theta = (float) ($thetaRaw !== false ? $thetaRaw : (string) self::DEFAULT_THETA);
        $theta = min(10.0, max(0.0, $theta));

        $nullConfirmations = $this->nullConfirmations($domain);
        if ($nullConfirmations === []) {
            // Пустой домен / нет нуллов: консервативный минимум (spelling RED:
            // noise-домен с нулл-подтверждениями 0 → q=1).
            return 1;
        }
        $median = self::median($nullConfirmations);

        return (int) min(self::Q_CAP, (int) ceil($theta * $median + 1.0));
    }

    /**
     * Подтверждения нуллов = базовая линия домена: confirmed_count всех
     * законов домена. Точные законы не исключаем — q считается ДО кандидата
     * (per-domain), медиана «сколько подтверждений набирает закон здесь
     * в среднем» и есть шумовой уровень, который кандидат обязан превысить.
     *
     * @return list<int>
     */
    private function nullConfirmations(string $domain): array
    {
        $stmt = Database::get()->prepare(
            'SELECT confirmed_count FROM laws WHERE domain = ?'
        );
        $stmt->execute([$domain]);

        $vals = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $v) {
            $vals[] = (int) $v;
        }

        return $vals;
    }

    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }
}
