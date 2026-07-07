<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * BeeWorker — wraps Bee for RoadRunner HTTP request handling.
 *
 * Each RoadRunner worker holds one BeeWorker → one Bee → one grammar.
 */
class BeeWorker
{
    private Bee $bee;

    private int $discoveries = 0;

    public function __construct(Bee $bee)
    {
        $this->bee = $bee;
    }

    public function bee(): Bee
    {
        return $this->bee;
    }

    /**
     * @return array{energy: float, grammar: string[], alive: bool, discoveries: int}
     */
    public function status(): array
    {
        return [
            'energy' => $this->bee->energy(),
            'grammar' => $this->bee->grammar(),
            'alive' => $this->bee->isAlive(),
            'discoveries' => $this->discoveries,
        ];
    }

    /**
     * Handle a task from Hive.
     *
     * @param string $body raw JSON task body
     * @return array{accepted: bool, grammar?: string[], error?: string}
     */
    public function handleTask(string $body): array
    {
        if (! $this->bee->isAlive()) {
            return [
                'accepted' => false,
                'error' => 'bee is dead',
            ];
        }

        $this->bee->chargeSearch();

        $task = json_decode($body, true);
        if (! $task || ! isset($task['data'])) {
            return [
                'accepted' => false,
                'error' => 'invalid task format',
            ];
        }

        return [
            'accepted' => true,
            'grammar' => $this->bee->grammar(),
        ];
    }
}
