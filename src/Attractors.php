<?php
declare(strict_types=1);

namespace BeeSwarm;

class Attractors
{
    private string $beeName;
    private float $energy;
    private float $curiosity;
    private float $virtue;
    private int $age;
    private int $solvedCount;
    
    public function __construct(string $name)
    {
        $this->beeName = $name;
        $this->energy = 1.0;
        $this->curiosity = 0.8;
        $this->virtue = 1.0;
        $this->age = 0;
        $this->solvedCount = 0;
    }
    
    public function update(bool $success, float $novelty = 0.0): void
    {
        if ($success) {
            $this->energy = min(1.5, $this->energy + 0.1);
            $this->curiosity = min(2.0, $this->curiosity + $novelty * 0.2);
            $this->solvedCount++;
        } else {
            $this->energy = max(0.05, $this->energy - 0.2);
            $this->curiosity = min(2.0, $this->curiosity + 0.3);
        }
        $this->age++;
        
        $db = Database::get();
        $db->prepare("INSERT INTO bee_log (bee_name, energy, curiosity, virtue, event) VALUES (?, ?, ?, ?, ?)")
           ->execute([$this->beeName, $this->energy, $this->curiosity, $this->virtue, $success ? 'success' : 'failure']);
    }
    
    public function shouldExplore(): bool
    {
        return $this->energy > 0.4 && $this->curiosity > 0.5;
    }
    
    public function state(): array
    {
        return [
            'energy' => round($this->energy, 2),
            'curiosity' => round($this->curiosity, 2),
            'virtue' => round($this->virtue, 2),
            'age' => $this->age,
            'solved' => $this->solvedCount,
        ];
    }
}
