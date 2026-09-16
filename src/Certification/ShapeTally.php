<?php

declare(strict_types=1);

namespace BeeSwarm\Certification;

use BeeSwarm\Core\LawShape;

/**
 * V0.11 WU-1: консенсус-таблица форм ансамбля (§1.9).
 *
 * Сигнатура результата члена = канон (ExpressionNormalizer) -> LawShape
 * маска (xN -> *, константы -> C). Runner Demo #3 использовал самодельный
 * shapeKey со str_replace; здесь переиспользуется LawShape (T2/T4, EVOLVE
 * DON'T ADD) — тот же контракт, что LawShapeTest уже закрепил.
 *
 * Дизайн-решение (вопрос 1 HANDOFF): B/BW- и R-атомы НЕ маскируются.
 * Имена детерминированы содержимым (BW+md5hex; R-атомы защищены
 * protectAtoms), одинаковый атом -> одинаковый токен -> консолидация
 * формы работает. Маскирование B->B обобщило бы РАЗНЫЕ слова роя в
 * ложный консенсус. Runner маскировал B[0-9a-f]{6,}->B defensive — в
 * Demo #3 атомов не было, маска не влияла на результат.
 *
 * Долю консенсуса считает вызывающий (WU-2): гейт (a) рецидив/ЗНАМЕНАТЕЛЬ
 * = foundN (члены с found=true), НЕ K — сюда консенсус-доля не вшита.
 */
final class ShapeTally
{
    /**
     * @var array<string, int> shape -> число членов
     */
    private array $shapes = [];

    /**
     * Добавить результат члена: сырая формула ИЛИ уже замаскированная
     * shape (LawShape::of идемпотентен — проба 15.09). Пустая строка =
     * член-отказ (found=false), в tally не попадает.
     */
    public function add(string $formulaOrShape): void
    {
        if ($formulaOrShape === '') {
            return;
        }
        $shape = LawShape::of($formulaOrShape);
        if ($shape === '') {
            return;
        }
        $this->shapes[$shape] = ($this->shapes[$shape] ?? 0) + 1;
    }

    /**
     * @return array<string, int> shape -> счёт, убывание по счёту
     */
    public function tally(): array
    {
        $out = $this->shapes;
        arsort($out);

        return $out;
    }

    /**
     * Консенсус-форма при доле >= minRate (от общего числа членов в
     * tally, т.е. вернувших кандидата). null = консенсуса нет.
     */
    public function consensus(float $minRate): ?string
    {
        foreach ($this->tally() as $shape => $count) {
            if ($shape === '') {
                continue;
            }
            if ($count / max(1, array_sum($this->shapes)) >= $minRate) {
                return $shape;
            }
        }

        return null;
    }
}
