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

        $task = json_decode($body, true);
        if (! $task || ! isset($task['data']) || empty($task['data'])) {
            return [
                'accepted' => false,
                'error' => 'invalid task format',
            ];
        }

        // Extract X and y from task data
        $data = $task['data'];
        $nCols = count($data[0]);
        foreach ($data as $row) {
            if (count($row) !== $nCols) {
                return ['accepted' => false, 'error' => 'inconsistent row lengths'];
            }
        }

        $X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
        $y = array_column($data, $nCols - 1);

        // Charge search energy BEFORE search (after validation)
        $this->bee->chargeSearch();

        // Use standard grammar (BASE_OPS + SEMANTIC_OPS)
        $grammar = new \BeeSwarm\Core\Grammar();

        [$found, $cv, $formula] = \BeeSwarm\Core\Search::find($X, $y, $grammar, 2);

        $result = [
            'accepted' => true,
            'grammar' => $this->bee->grammar(),
        ];

        if ($found && $cv <= 0.01) {
            $result['discovery'] = ['formula' => $formula, 'cv' => $cv];
            $this->discoveries++;
            $this->bee->rewardDiscovery();
        }

        return $result;
    }
}
