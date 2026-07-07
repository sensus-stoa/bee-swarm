<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

/**
 * Strategies extracted from Forager (D10 Phase 1).
 *
 * Currently a bridge — closures live in Forager until Phase 2.
 */
class Strategies
{
    /**
     * @var array<string, callable>
     */
    private array $strategies;

    public function __construct()
    {
        // Bridge: instantiate Forager to access its private strategies
        $forager = new Forager();
        $this->strategies = $forager->getStrategiesForExtraction();
    }

    /**
     * @return array<string, callable>
     */
    public function all(): array
    {
        return $this->strategies;
    }
}
