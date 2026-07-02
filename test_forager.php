<?php
// ~/.bee_swarm/test_forager.php
// ТЕСТ: рой ищет новые данные при голоде

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\AtomRegistry;

$home = getenv('HOME');
$baseDir = $home . '/Documents/the_lair';

// ═══ FORAGER: сканер файловой системы ═══

function forageForNumbers(string $dir, int $maxFiles = 50): array {
    $found = [];
    $count = 0;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($count >= $maxFiles) break;
        
        $ext = $file->getExtension();
        if (!in_array($ext, ['md', 'json', 'jsonl', 'csv', 'txt'])) continue;
        
        $path = $file->getPathname();
        
        // Пропускаем уже известные пути
        if (str_contains($path, '.git/')) continue;
        if (str_contains($path, 'venv/')) continue;
        if (str_contains($path, 'node_modules/')) continue;
        
        // Пропускаем бинарные и огромные
        if ($file->getSize() > 500_000) continue;
        
        $content = file_get_contents($path);
        if (!$content) continue;
        
        // Ищем строки с числами
        $lines = explode("\n", $content);
        $numericLines = [];
        
        foreach ($lines as $line) {
            // Ищем 2+ числа в строке
            preg_match_all('/-?\d+\.?\d*/', $line, $matches);
            if (count($matches[0]) >= 2) {
                $numbers = array_map('floatval', $matches[0]);
                $numericLines[] = $numbers;
            }
        }
        
        if (count($numericLines) >= 3) {
            // Генерируем задачи: первое число → остальные (и наоборот)
            // Задача 1: col[0] → col[1]
            $data1 = [];
            foreach ($numericLines as $nums) {
                if (count($nums) >= 2) $data1[] = [$nums[0], $nums[1]];
            }
            if (count($data1) >= 3) {
                $fname = str_replace($dir . '/', '', $path);
                $found[] = ['name' => "file:" . basename($fname) . "_c0c1", 'data' => $data1, 'domain' => 'foraged', 'source' => $path];
            }
            
            // Задача 2: col[0] × col[1] → col[2] (если есть третья колонка)
            if (count($numericLines[0]) >= 3) {
                $data2 = [];
                foreach ($numericLines as $nums) {
                    if (count($nums) >= 3) $data2[] = [$nums[0], $nums[1], $nums[2]];
                }
                if (count($data2) >= 3) {
                    $found[] = ['name' => "file:" . basename($fname) . "_c01c2", 'data' => $data2, 'domain' => 'foraged', 'source' => $path];
                }
            }
            
            $count++;
        }
    }
    
    return $found;
}

// ═══ ТЕСТ ═══
echo "══════════════════════════════\n";
echo "  FORAGER: scanning $baseDir\n";
echo "══════════════════════════════\n\n";

$foraged = forageForNumbers($baseDir);
echo "Files with data: " . count($foraged) . "\n\n";

if ($foraged) {
    // Показываем источники (уникальные файлы)
    $sources = array_unique(array_column($foraged, 'source'));
    echo "Sources:\n";
    foreach ($sources as $s) {
        $fname = str_replace($baseDir . '/', '', $s);
        echo "  $fname\n";
    }
    
    echo "\nSample tasks:\n";
    foreach (array_slice($foraged, 0, 5) as $task) {
        printf("  %-40s %d points\n", $task['name'], count($task['data']));
    }
    
    // ═══ ПРОВЕРЯЕМ: есть ли законы? ═══
    echo "\n══════════════════════════════\n";
    echo "  TESTING FORAGED TASKS\n";
    echo "══════════════════════════════\n\n";
    
    $foundLaws = 0;
    foreach ($foraged as $task) {
        $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
        $y = array_column($task['data'], count($task['data'][0]) - 1);
        
        $discovered = AtomRegistry::discover($X, $y);
        if ($discovered) {
            $foundLaws++;
            echo "✅ {$task['name']}: " . implode(', ', array_column($discovered, 'atom')) . "\n";
        }
    }
    
    echo "\nLaws found: $foundLaws/" . count($foraged) . "\n";
    
    if ($foundLaws > 0) {
        echo "\nРой нашёл еду в новых файлах. Forager работает.\n";
    } else {
        echo "\nЧисел много, законов нет. Нужны структурированные данные (CSV, таблицы).\n";
    }
}
