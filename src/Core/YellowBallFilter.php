<?php
declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * YellowBallFilter — детектит кандидатов-«жёлтые мячики».
 *
 * Кандидат с train_CV < 0.01 но held-out_CV > 0.05 — шумовая ловушка.
 * Выглядит как открытие, но не воспроизводится на новых данных.
 */
class YellowBallFilter
{
    private string $taskName;

    /** @var list<array{train: float, heldOut: float}> */
    private array $candidates = [];

    public function __construct(string $taskName)
    {
        $this->taskName = $taskName;
    }

    public function addCandidate(float $trainCv, float $heldOutCv): void
    {
        $this->candidates[] = ['train' => $trainCv, 'heldOut' => $heldOutCv];
    }

    /**
     * @return array{ready: bool, yellow: list<array>, valid: list<array>}
     */
    public function evaluate(int $heldOutRequired = 3): array
    {
        if (count($this->candidates) < $heldOutRequired) {
            return ['ready' => false, 'yellow' => [], 'valid' => []];
        }

        $yellow = [];
        $valid = [];

        foreach ($this->candidates as $c) {
            // train low (<0.01) but held-out high (>0.05) → yellow ball
            if ($c['train'] < 0.01 && $c['heldOut'] > 0.05) {
                $yellow[] = $c;
            } else {
                $valid[] = $c;
            }
        }

        return ['ready' => true, 'yellow' => $yellow, 'valid' => $valid];
    }
}
