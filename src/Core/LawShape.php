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
    /**
     * Инвариант формы: канон с замаскированными листьями.
     */
    public static function of(string $formula): string
    {
        return self::mask(ExpressionNormalizer::normalize($formula));
    }

    /**
     * VERIF-COLLABEL-PARITY (08.10): перевод доменных имён колонок в generic xN.
     *
     * Конвенция — ТОЧНАЯ инверсия Search::testCv:1427-1434 (домен→xN там же):
     * map label→"x{i}", uksort по длине DESC (longest-first), str_replace.
     * Без longest-first 'q' съедает префикс 'q_lo' → 'x0_lo'.
     *
     * Применяется к КАНОНУ (normalize → toGeneric → mask): spawnForLaw
     * пишет law_shape = of(toGeneric(canon, labels)) — паритет масок с
     * generic-путём V0.14 WU-5.
     *
     * @param array<int, string>|null $colLabels позиция → имя колонки
     */
    public static function toGeneric(string $formula, ?array $colLabels): string
    {
        if ($colLabels === null || $colLabels === []) {
            return $formula;
        }
        $map = [];
        foreach ($colLabels as $i => $label) {
            // TESTCV-TOGENERIC-DEDUP (08.10): numeric-строка ('5') проходит
            // is_string, но PHP int-cast превращает ключ '5' в int → needle
            // '(Kx0+x1)' из '(K5+feat)' (порча K-константы). Пустая метка даёт
            // хвостовой needle '' (str_replace стирает все позиции). Обе —
            // skip: конвенция переводит ТОЛЬКО строковые имена колонок.
            if (! is_string($label) || $label === '' || is_numeric($label) || $label === "x{$i}") {
                continue;
            }
            // ПРЕДУСЛОВИЕ инъективности (triage premortem Х2, 08.10): метка
            // вида xN на ЧУЖОЙ позиции (feat на i=1 при занятом x1) схлопнет
            // две колонки в один placeholder — колонки датасета с именами
            // xN обязаны стоять на своих позициях (инвариант ingestion).
            $map[$label] = "x{$i}";
        }
        if ($map === []) {
            return $formula;
        }
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return (string) str_replace(array_keys($map), array_values($map), $formula);
    }

    /**
     * Law-distance: 0 = одна форма (form-invariant), 1 = разные.
     */
    public static function distance(string $a, string $b): int
    {
        return self::of($a) === self::of($b) ? 0 : 1;
    }

    private static function mask(string $canon): string
    {
        // ПОРЯДОК ВАЖЕН: сначала атомы колонок (x0 — цифра внутри имени!),
        // потом константы. Иначе \d+ съедает '0' из 'x0' → xC вместо *.
        $masked = (string) preg_replace('/\bx\d+\b/', '*', $canon);
        return (string) preg_replace('/K\d+|(?<![\w.])\d+(?:\.\d+)?/', 'C', $masked);
    }
}
