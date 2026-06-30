<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * PersistentHive: популяция с состоянием в SQLite.
 * Переживает перезапуски. Все воркеры видят одно.
 */
class PersistentHive
{
    private array $bees = [];
    private int $generation = 0;
    private string $name;
    
    public function __construct(string $name = 'default')
    {
        $this->name = $name;
        $this->loadState();
        if (empty($this->bees)) {
            if ($name === 'hive_b') {
                $this->bees[] = new CellBee('language');
                $this->bees[] = new CellBee('ethics');
            } else {
                $this->bees[] = new CellBee('arithmetic');
                $this->bees[] = new CellBee('physics');
            }
        }
    }
    
    private function loadState(): void
    {
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS hive_state (
            key TEXT PRIMARY KEY, value TEXT
        )");
        $row = $db->query("SELECT value FROM hive_state WHERE key='bees_{$this->name}'")->fetch();
        if ($row) {
            $data = json_decode($row['value'], true);
            $this->bees = [];
            foreach ($data as $bdata) {
                $bee = new CellBee($bdata['domain']);
                $bee->id = $bdata['id'];
                $bee->energy = $bdata['energy'];
                $bee->successes = $bdata['successes'];
                $bee->failures = $bdata['failures'];
                $bee->grammar = new RelationGrammar($bdata['domain']);
                // Restore grammar relations
                foreach ($bdata['grammar'] as $rel) {
                    if (!in_array($rel, $bee->grammar->all())) {
                        $bee->grammar->all(); // force init
                    }
                }
                $this->bees[] = $bee;
            }
        }
        $genRow = $db->query("SELECT value FROM hive_state WHERE key='generation'")->fetch();
        if ($genRow) $this->generation = (int)$genRow['value'];
    }
    
    private function saveState(): void
    {
        $db = Database::get();
        $beesData = [];
        foreach ($this->bees as $b) {
            $beesData[] = [
                'id' => $b->id, 'domain' => $b->domain,
                'energy' => $b->energy, 'successes' => $b->successes,
                'failures' => $b->failures, 'grammar' => $b->grammar->all(),
            ];
        }
        $db->prepare("INSERT OR REPLACE INTO hive_state (key, value) VALUES ('bees_{$this->name}', ?)")
           ->execute([json_encode($beesData)]);
        $db->prepare("INSERT OR REPLACE INTO hive_state (key, value) VALUES ('generation_{$this->name}', ?)")
           ->execute([$this->generation]);
    }
    
    public function tick(): array
    {
        $this->generation++;
        
        $tasks = $this->getTasks();
        if (empty($tasks)) {
            return ['status' => 'no_tasks', 'bees' => count($this->bees)];
        }
        
        $task = $tasks[array_rand($tasks)];
        $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
        $y = array_map(fn($r) => end($r), $task['data']);
        
        // Router: pick bee with lowest CV on this task
        $best = null;
        $bestCv = 9.99;
        foreach ($this->bees as $bee) {
            if (!$bee->isReady()) continue;
            $result = $bee->search($X, $y);
            if ($result[1] < $bestCv) { $bestCv = $result[1]; $best = $bee; }
        }
        
        if (!$best) {
            return ['status' => 'all_tired', 'bees' => count($this->bees)];
        }
        
        $result = $best->live($X, $y);
        
        if ($best->isDead()) {
            $this->bees = array_filter($this->bees, fn($b) => $b !== $best);
            $result['event'] = 'died';
        }
        
        $child = $best->divide();
        if ($child) {
            $this->bees[] = $child;
            $result['event'] = 'divided';
        }
        
        $this->log[] = $result;
        if (count($this->log) > 100) array_shift($this->log);
        
        $this->saveState();
        
        return [
            'generation' => $this->generation,
            'bees_count' => count($this->bees),
            'alive' => array_map(fn($b) => [
                'id' => $b->id, 'domain' => $b->domain,
                'energy' => round($b->energy, 2),
                'grammar' => $b->grammar->all(),
                'successes' => $b->successes,
            ], $this->bees),
            'latest' => $result,
        ];
    }
    
    private function getTasks(): array
    {
        return [
            ['name'=>'AND','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
            ['name'=>'Add','data'=>[[1,2,3],[3,4,7],[5,6,11],[2,2,4]]],
            ['name'=>'OR','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
            ['name'=>'Mul','data'=>[[1,2,2],[2,3,6],[3,4,12],[5,6,30]]],
        ];
    }
    
    public function bees(): array { return $this->bees; }
    public function gen(): int { return $this->generation; }
    public function beeCount(): int { return count($this->bees); }
    public function bumpGeneration(): void { $this->generation++; }
    public function removeBee(CellBee $bee): void { $this->bees = array_filter($this->bees, fn($b) => $b !== $bee); }
    public function addBee(CellBee $bee): void { $this->bees[] = $bee; }
    public function save(): void { $this->saveState(); }
}
