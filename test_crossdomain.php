<?php
// ~/.bee_swarm/test_crossdomain.php
// ГИПОТЕЗА: рой собирает любые цветы (источники данных).
// Общий ключ = дата. Через неё — кросс-доменные задачи.
// CV→0 находит законы, связывающие арифметику и семантику.

date_default_timezone_set('Europe/Moscow');
$logFile = '/tmp/crossdomain.log';
file_put_contents($logFile, '');

function logMsg(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// ═══ ИСТОЧНИКИ: все цветы ═══

// ЦВЕТОК 1: метрики пользователя
function harvestMetrics(): array {
    $home = getenv('HOME');
    $paths = [
        $home . '/Documents/the_lair/ExoCortex/Journal/global/metrics.jsonl',
        $home . '/Documents/the_lair/ExoCortex/Отчёты/global/metrics.jsonl',
        $home . '/.bee_swarm/data/metrics.jsonl',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            $rows = [];
            foreach (file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $r = json_decode($line, true);
                if ($r && isset($r['date'])) $rows[] = $r;
            }
            if ($rows) {
                logMsg("🌼 metrics: " . count($rows) . " days");
                return $rows;
            }
        }
    }
    logMsg("⚠️ metrics: not found");
    return [];
}

// ЦВЕТОК 2: daily notes из Obsidian (текст + ссылки)
function harvestDailyNotes(): array {
    $home = getenv('HOME');
    $journalDir = $home . '/Documents/the_lair/ExoCortex/Journal/2026';
    if (!is_dir($journalDir)) {
        logMsg("⚠️ daily notes: dir not found ($journalDir)");
        return [];
    }
    
    $notes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($journalDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'md') continue;
        $path = $file->getPathname();
        $content = file_get_contents($path);
        if (!$content) continue;
        
        // Извлечь дату из имени файла (DD.MM.YYYY.md)
        $filename = $file->getFilename();
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $filename, $m)) continue;
        $date = "{$m[3]}-{$m[2]}-{$m[1]}";
        
        // Извлечь wikilinks (концепты)
        preg_match_all('/\[\[([^\]|#]+)(?:[|#][^\]]+)?\]\]/', $content, $matches);
        $concepts = $matches[1] ?? [];
        
        // Считать концепты
        $conceptCounts = array_count_values($concepts);
        
        // Извлечь числа (метрики в теле заметки)
        $metricsInBody = [];
        if (preg_match_all('/(pulse|пульс|gi|dq|stress|стресс|discipline|дисциплина|sleep|сон|energy|энергия|intact|целостность)[:\s]*(\d+)/ui', $content, $mm)) {
            foreach ($mm[1] as $i => $key) {
                $k = strtolower($key);
                $k = str_replace(['пульс','стресс','дисциплина','сон','энергия','целостность'], 
                                 ['pulse','stress','discipline','sleep','energy','intact'], $k);
                $metricsInBody[$k] = (int)$mm[2][$i];
            }
        }
        
        $notes[] = [
            'date' => $date,
            'path' => $path,
            'concepts' => $conceptCounts,
            'total_concepts' => count($concepts),
            'total_words' => str_word_count($content),
            'metrics_in_body' => $metricsInBody,
        ];
    }
    
    usort($notes, fn($a,$b) => $a['date'] <=> $b['date']);
    logMsg("🌼 daily notes: " . count($notes) . " days with notes");
    return $notes;
}

// ЦВЕТОК 3: структура концептов (Zettelkasten insights)
function harvestConcepts(array $dailyNotes): array {
    $allConcepts = [];
    foreach ($dailyNotes as $note) {
        foreach ($note['concepts'] as $concept => $count) {
            if (!isset($allConcepts[$concept])) {
                $allConcepts[$concept] = [
                    'total_mentions' => 0,
                    'days_mentioned' => 0,
                    'first_seen' => $note['date'],
                    'last_seen' => $note['date'],
                    'by_date' => [],
                ];
            }
            $allConcepts[$concept]['total_mentions'] += $count;
            $allConcepts[$concept]['days_mentioned']++;
            $allConcepts[$concept]['last_seen'] = $note['date'];
            $allConcepts[$concept]['by_date'][$note['date']] = $count;
        }
    }
    logMsg("🌼 concepts: " . count($allConcepts) . " unique");
    return $allConcepts;
}

// ═══ ГЕНЕРАЦИЯ КРОСС-ДОМЕННЫХ ЗАДАЧ ═══

function generateTasks(array $metrics, array $notes, array $concepts): array {
    $tasks = [];
    
    // Строим индекс: date → metrics
    $metricsByDate = [];
    foreach ($metrics as $r) {
        $d = $r['date'] ?? ($r['day'] ?? null);
        if ($d) $metricsByDate[$d] = $r;
    }
    
    if (!$metricsByDate) {
        logMsg("⚠️ No metrics by date — skipping cross-domain");
        return [];
    }
    
    // Строим индекс: date → concepts
    $conceptsByDate = [];
    foreach ($notes as $note) {
        $conceptsByDate[$note['date']] = $note['concepts'];
    }
    
    // Находим даты где есть И метрики И заметки
    $commonDates = array_intersect(array_keys($metricsByDate), array_keys($conceptsByDate));
    $commonDates = array_values($commonDates);
    sort($commonDates);
    
    logMsg("📅 Common dates (metrics+notes): " . count($commonDates));
    
    if (count($commonDates) < 5) return [];
    
    // ═══ ЗАДАЧА 1: метрика ↔ интенсивность мышления ═══
    // X = total_words (объём заметки), Y = каждая метрика
    foreach (['stress','pulse','gi','dq','energy','discipline','sleep','intact'] as $metric) {
        $data = [];
        foreach ($commonDates as $d) {
            $y = $metricsByDate[$d][$metric] ?? null;
            $words = $notes[array_search($d, array_column($notes, 'date'))]['total_words'] ?? 0;
            if ($y !== null && $words > 0) {
                $data[] = [(float)$words, (float)$y];
            }
        }
        if (count($data) >= 5) {
            $tasks[] = ['name' => "words→$metric", 'data' => $data, 'domain' => 'cross-metrics-text'];
        }
    }
    
    // ═══ ЗАДАЧА 2: упоминания концепта → метрика ═══
    // X = mention_count концепта, Y = метрика
    $topConcepts = array_slice($concepts, 0, 20); // топ-20 по упоминаниям
    uasort($topConcepts, fn($a,$b) => $b['total_mentions'] <=> $a['total_mentions']);
    
    foreach (['stress','pulse','dq'] as $metric) {
        foreach (array_slice(array_keys($topConcepts), 0, 8) as $concept) {
            $data = [];
            foreach ($commonDates as $d) {
                $y = $metricsByDate[$d][$metric] ?? null;
                $mentions = $conceptsByDate[$d][$concept] ?? 0;
                if ($y !== null) {
                    $data[] = [(float)$mentions, (float)$y];
                }
            }
            if (count($data) >= 5 && count(array_unique(array_column($data, 0))) >= 2) {
                $tasks[] = [
                    'name' => "mention($concept)→$metric", 
                    'data' => $data, 
                    'domain' => 'cross-concept-metric'
                ];
            }
        }
    }
    
    // ═══ ЗАДАЧА 3: разнообразие концептов (уникальных за день) → метрика ═══
    $dataDiversity = [];
    foreach ($commonDates as $d) {
        $diversity = count($conceptsByDate[$d] ?? []);
        foreach (['stress','dq','energy'] as $metric) {
            $y = $metricsByDate[$d][$metric] ?? null;
            if ($y !== null && $diversity > 0) {
                $dataDiversity[$metric][] = [(float)$diversity, (float)$y];
            }
        }
    }
    foreach ($dataDiversity as $metric => $data) {
        if (count($data) >= 5) {
            $tasks[] = ['name' => "diversity→$metric", 'data' => $data, 'domain' => 'cross-diversity-metric'];
        }
    }
    
    // ═══ ЗАДАЧА 4: номер дня (время) → метрика (тренд) ═══
    foreach (['stress','energy','dq','discipline'] as $metric) {
        $data = [];
        for ($i = 0; $i < count($commonDates); $i++) {
            $d = $commonDates[$i];
            $y = $metricsByDate[$d][$metric] ?? null;
            if ($y !== null) {
                $data[] = [(float)$i, (float)$y];
            }
        }
        if (count($data) >= 5) {
            $tasks[] = ['name' => "day_number→$metric", 'data' => $data, 'domain' => 'cross-time-metric'];
        }
    }
    
    logMsg("🧩 Generated " . count($tasks) . " cross-domain tasks");
    return $tasks;
}

// ═══ ТЕСТ: CV→0 на ВСЕХ задачах ═══

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Grammar;
use BeeSwarm\Search;

function cv(array $vec, array $y): float {
    $n = count($vec);
    $exact = true;
    for ($i = 0; $i < $n; $i++) {
        if (abs(($vec[$i] ?? 9e99) - $y[$i]) > 0.001) { $exact = false; break; }
    }
    if ($exact) return 0.0;
    $ratio = [];
    for ($i = 0; $i < $n; $i++) $ratio[] = ($vec[$i] ?? 0) / ($y[$i] + 1e-8);
    $mean = array_sum($ratio) / $n;
    if (abs($mean) < 1e-8) return 9.99;
    $var = 0;
    foreach ($ratio as $r) $var += ($r - $mean) ** 2;
    return sqrt($var / $n) / abs($mean);
}

// ═══ MAIN ═══
logMsg("═══ CROSS-DOMAIN FLOWER HARVEST ═══");
logMsg("");

$metrics = harvestMetrics();
$notes = harvestDailyNotes();
$concepts = harvestConcepts($notes);

if (!$metrics || !$notes) {
    logMsg("❌ Not enough flowers. Need metrics + daily notes.");
    exit(1);
}

$tasks = generateTasks($metrics, $notes, $concepts);
if (!$tasks) {
    logMsg("❌ No cross-domain tasks generated.");
    exit(1);
}

// ═══ ПРОГОН ═══
logMsg("");
logMsg("═══ SEARCHING LAWS ═══");
logMsg("");

$grammar = new Grammar();
$ops = $grammar->all();
logMsg("Grammar: " . implode(', ', $ops) . " (" . count($ops) . " ops)");

$results = ['laws' => [], 'partial' => [], 'chaos' => []];
$maxPoints = 30;

foreach ($tasks as $task) {
    $data = $task['data'];
    if (count($data) > $maxPoints) {
        $keys = array_rand($data, $maxPoints);
        $data = array_map(fn($k) => $data[$k], $keys);
        $data = array_values($data);
    }
    
    $X = array_map(fn($r) => array_slice($r, 0, -1), array_values($data));
    $y = array_column($data, count($data[0]) - 1);
    
    $g = new Grammar();
    [$ok, $cvFound, $formula] = Search::find($X, $y, $g, 3);
    
    if ($ok) {
        $results['laws'][] = ['task' => $task['name'], 'cv' => $cvFound, 'formula' => $formula, 'domain' => $task['domain'], 'points' => count($data)];
        logMsg("✅ {$task['name']}: $formula (CV=" . round($cvFound,4) . ", {$task['domain']})");
    } elseif ($cvFound < 0.5) {
        $results['partial'][] = ['task' => $task['name'], 'cv' => $cvFound, 'formula' => $formula, 'domain' => $task['domain']];
        if (count($results['partial']) <= 10) {
            logMsg("🔍 {$task['name']}: $formula (CV=" . round($cvFound,4) . ")");
        }
    } else {
        $results['chaos'][] = $task['name'];
    }
}

// ═══ ИТОГИ ═══
logMsg("");
logMsg("═══ HARVEST RESULTS ═══");
logMsg("Laws found (CV=0): " . count($results['laws']));
logMsg("Partial (CV<0.5):  " . count($results['partial']));
logMsg("Chaos (CV≥0.5):    " . count($results['chaos']));

if ($results['laws']) {
    logMsg("");
    logMsg("Laws by domain:");
    $byDomain = [];
    foreach ($results['laws'] as $l) {
        $byDomain[$l['domain']] = ($byDomain[$l['domain']] ?? 0) + 1;
    }
    foreach ($byDomain as $dom => $cnt) {
        logMsg("  $dom: $cnt");
    }
    
    // Показать лучшие найденные законы
    logMsg("");
    logMsg("Top laws:");
    foreach (array_slice($results['laws'], 0, 15) as $l) {
        logMsg("  {$l['task']} → {$l['formula']} (CV=" . round($l['cv'],4) . ")");
    }
}

if ($results['partial']) {
    logMsg("");
    logMsg("Best partials (potential laws with more data):");
    usort($results['partial'], fn($a,$b) => $a['cv'] <=> $b['cv']);
    foreach (array_slice($results['partial'], 0, 10) as $p) {
        logMsg("  {$p['task']}: {$p['formula']} (CV=" . round($p['cv'],4) . ")");
    }
}

logMsg("");
logMsg("Done. Log: $logFile");
