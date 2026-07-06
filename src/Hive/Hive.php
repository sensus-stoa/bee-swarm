<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Forager\Forager;

/**
 * Hive — главный цикл роя.
 * agenda.php → (new Hive(...))->run()
 */
class Hive
{
    private PlateauDetector $plateau;
    private Forager $forager;
    private array $tasks;
    private ?int $maxTicks;

    public function __construct(
        PlateauDetector $plateau,
        Forager $forager,
        ?array $tasks = null,
        ?int $maxTicks = null,
    ) {
        $this->plateau = $plateau;
        $this->forager = $forager;
        $this->tasks = $tasks ?? [];
        $this->maxTicks = $maxTicks;
    }

    /** Один цикл: выбор задачи → discover → статус */
    public function tick(): array
    {
        $discoveries = 0;

        if (!empty($this->tasks)) {
            $task = $this->tasks[array_rand($this->tasks)];

            // TODO: реальный discover (пока заглушка)
            if (isset($task['data'])) {
                // $found = AtomRegistry::discover(...) — позже
                $discoveries = 0;
            }
        }

        return [
            'tasks_processed' => count($this->tasks),
            'discoveries' => $discoveries,
        ];
    }

    /** Главный цикл */
    public function run(): int
    {
        $ticks = 0;

        while (true) {
            $this->tick();
            $ticks++;

            if ($this->maxTicks !== null && $ticks >= $this->maxTicks) {
                break;
            }

            usleep(200_000); // base sleep
        }

        return $ticks;
    }
}
