<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * RESOURCE SCHEDULER — распределение ресурсов между секторами.
 *
 * Принцип: каждый сектор имеет минимальную квоту (exploration guarantee),
 * даже если его текущий fitness плох. Это защищает T2−T1 от раннего убийства.
 *
 * Бюджет = baseSectorBudget × novelty × lineagePotential / computeCost
 * (пока без сложной формулы — минимальная версия: equal quotas + novelty boost)
 */
class ResourceScheduler
{
    /** @var array<string, float> базовые квоты секторов (сумма = 1.0) */
    private array $baseQuotas;

    /** @var int максимальное число materialized bees за tick */
    private int $maxMaterialized;

    public function __construct(array $baseQuotas = [], int $maxMaterialized = 50)
    {
        $this->baseQuotas = $baseQuotas ?: [
            'DIFF' => 0.20,
            'PRODUCT' => 0.20,
            'RATIO' => 0.15,
            'POWER' => 0.10,
            'SQRT' => 0.10,
            'ADDITIVE' => 0.10,
            'unknown' => 0.15,
        ];
        $this->maxMaterialized = $maxMaterialized;
    }

    /**
     * Рассчитать квоты для текущего tick.
     * Возвращает: ['DIFF' => 10, 'PRODUCT' => 10, ...] (число awaken из пула).
     */
    public function computeQuotas(int $poolSize): array
    {
        $quotas = [];
        foreach ($this->baseQuotas as $sector => $share) {
            $quotas[$sector] = max(1, (int) floor($share * $this->maxMaterialized));
        }
        return $quotas;
    }

    /**
     * Сколько рецептов материализовать в этом tick.
     * Ограничено: maxMaterialized, poolSize, бюджет CPU.
     */
    public function materializationBudget(int $poolSize, float $cpuLoad = 0.0): int
    {
        // При высокой нагрузке CPU — уменьшаем материализацию
        $factor = max(0.2, 1.0 - $cpuLoad);
        return max(5, (int) round($this->maxMaterialized * $factor));
    }

    public function maxMaterialized(): int
    {
        return $this->maxMaterialized;
    }

    public function baseQuotas(): array
    {
        return $this->baseQuotas;
    }
}
