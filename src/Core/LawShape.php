<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * T4 (story theorem-level): инвариант формы закона + law-distance.
 *
 * Канон (ExpressionNormalizer) различает законы — одна функция = одно слово (T2).
 * Но СТРУКТУРА формы может переноситься между законами: y=2x и y=3x имеют
 * одну форму «линейная», y=x² — другую. Это основа transfer-метрики:
 * закон-дистанция = сравнение инвариантов формы.
 *
 * Инвариант = канон с замаскированными листьями:
 *   атомы колонок (x0, x1, Rmaxx0...) → *
 *   константы (K1/K2/K3, числовые литералы) → C
 * Примеры: y=2x → (*×C); y=x → *; y=x² → (*×*).
 *
 * Бинарная метрика: same shape → 0, разные → 1.
 * Расширение до градуированной метрики — отдельная работа (T4-post).
 */
final class LawShape
{
    /** Инвариант формы: канон с замаскированными листьями. */
    public static function of(string $formula): string
    {
        return self::mask(ExpressionNormalizer::normalize($formula));
    }

    /** Law-distance: 0 = одна форма (form-invariant), 1 = разные. */
    public static function distance(string $a, string $b): int
    {
        return self::of($a) === self::of($b) ? 0 : 1;
    }

    private static function mask(string $canon): string
    {
        // ПОРЯДОК ВАЖЕН: сначала атомы колонок (x0 — цифра внутри имени!),
        // потом константы. Иначе \d+ съедает '0' из 'x0' → xC вместо *.
        $masked = (string) preg_replace('/\bx\d+\b/', '*', $canon);
        return (string) preg_replace('/K[1-9]|(?<![\w.])\d+(?:\.\d+)?/', 'C', $masked);
    }
}
