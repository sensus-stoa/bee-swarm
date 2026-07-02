<?php
// ТЕСТ: логические отношения из Obsidian → CV→0
// Гипотеза: отношения между концептами — логические, не статистические
// is_a, links_to, contains → CV→0 если инвариантны

$home = getenv('HOME');
$journalDir  = $home . '/Documents/the_lair/ExoCortex/Journal/2026';
$globalDir   = $home . '/Documents/the_lair/ExoCortex/Journal/global';
$insightsDir = $globalDir . '/insights';

// ═══ ИСТОЧНИК 1: структура папок → is_a ═══
function extractFolderHierarchy(string $baseDir): array {
    $facts = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'md') continue;
        $relPath = str_replace($baseDir . '/', '', $file->getPathname());
        $parts = explode('/', $relPath);
        if (count($parts) >= 2) {
            // Файл в подпапке: file is_a folder_concept
            $folder = $parts[count($parts)-2];
            $fileConcept = str_replace('.md', '', $parts[count($parts)-1]);
            $facts[] = ['s' => $fileConcept, 'p' => 'is_a', 'o' => $folder];
        }
    }
    return $facts;
}

// ═══ ИСТОЧНИК 2: wikilinks → links_to ═══
function extractWikilinks(string $baseDir): array {
    $facts = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'md') continue;
        $content = file_get_contents($file->getPathname());
        if (!$content) continue;
        
        $src = str_replace('.md', '', $file->getFilename());
        
        preg_match_all('/\[\[([^\]|#]+)(?:[|#][^\]]+)?\]\]/', $content, $matches);
        foreach ($matches[1] as $target) {
            $target = trim($target);
            if ($target && $target !== $src) {
                $facts[] = ['s' => $src, 'p' => 'links_to', 'o' => $target];
            }
        }
    }
    return $facts;
}

// ═══ ИСТОЧНИК 3: explicit "X — это Y" в тексте ═══
function extractIsA(string $filePath): array {
    if (!file_exists($filePath)) return [];
    $content = file_get_contents($filePath);
    $facts = [];
    
    // Паттерн: X — это Y
    if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s*[—–-]\s*это\s+([А-Яа-яA-Za-z_]+)/u', $content, $mm)) {
        for ($i = 0; $i < count($mm[0]); $i++) {
            $facts[] = ['s' => trim($mm[1][$i]), 'p' => 'is_a', 'o' => trim($mm[2][$i])];
        }
    }
    // Паттерн: X является Y
    if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s+является\s+([А-Яа-яA-Za-z_]+)/u', $content, $mm)) {
        for ($i = 0; $i < count($mm[0]); $i++) {
            $facts[] = ['s' => trim($mm[1][$i]), 'p' => 'is_a', 'o' => trim($mm[2][$i])];
        }
    }
    return $facts;
}

echo "══════════════════════════════════════\n";
echo "  LOGICAL RELATIONS FROM OBSIDIAN\n";
echo "══════════════════════════════════════\n\n";

// ═══ СБОР ФАКТОВ ═══
$facts = [];

// 1. Из структуры папок
$hierarchy = extractFolderHierarchy($home . '/Documents/the_lair');
echo "Folder hierarchy:    " . count($hierarchy) . " facts\n";
$facts = array_merge($facts, $hierarchy);

// 2. Из wikilinks
$wikilinks = extractWikilinks($journalDir);
echo "Wikilinks:           " . count($wikilinks) . " facts\n";
$facts = array_merge($facts, $wikilinks);

// 3. Из текста (insights + core files)
$textFacts = [];
foreach (array_merge(
    glob($insightsDir . '/*.md') ?: [],
    glob($globalDir . '/core/*.md') ?: [],
    glob($globalDir . '/strategy/*.md') ?: []
) as $f) {
    $textFacts = array_merge($textFacts, extractIsA($f));
}
echo "Explicit 'X — это Y': " . count($textFacts) . " facts\n";
$facts = array_merge($facts, $textFacts);

$total = count($facts);
echo "TOTAL facts:         $total\n\n";

if ($total < 10) {
    echo "Not enough facts for CV analysis.\n";
    exit;
}

// ═══ АНАЛИЗ: CV→0 НА ЛОГИЧЕСКИХ ОТНОШЕНИЯХ ═══

echo "══════════════════════════════════════\n";
echo "  TESTING INVARIANTS\n";
echo "══════════════════════════════════════\n\n";

// ТЕСТ 1: Транзитивность → CV→0?
echo "─── TRANSITIVITY ───\n";
foreach (['is_a', 'links_to'] as $pred) {
    $predFacts = array_filter($facts, fn($f) => $f['p'] === $pred);
    $bySubject = [];
    $byObject = [];
    foreach ($predFacts as $f) {
        $bySubject[$f['s']][] = $f['o'];
        $byObject[$f['o']][] = $f['s'];
    }
    
    // Найти цепочки A→B→C
    $chains = 0;
    $transitive = 0;
    $data = []; // [has_chain, has_direct]
    
    foreach ($bySubject as $a => $bs) {
        foreach ($bs as $b) {
            if (!isset($bySubject[$b])) continue;
            foreach ($bySubject[$b] as $c) {
                $chains++;
                $hasDirect = in_array($c, $bySubject[$a] ?? []);
                if ($hasDirect) $transitive++;
                $data[] = [1.0, $hasDirect ? 1.0 : 0.0];
            }
        }
    }
    
    if ($chains > 0) {
        // CV→0: если все цепочки имеют прямую связь → транзитивно
        $y = array_column($data, 1);
        $n = count($y);
        $allOne = count(array_filter($y, fn($v) => $v > 0.5)) === $n;
        $allZero = count(array_filter($y, fn($v) => $v < 0.5)) === $n;
        
        if ($allOne) {
            echo "  ✅ $pred: ТРАНЗИТИВНО (CV=0, $chains цепочек, все подтверждены)\n";
        } elseif ($allZero) {
            echo "  🔍 $pred: АНТИТРАНЗИТИВНО (CV=0, $chains цепочек, ни одна не подтверждена)\n";
        } else {
            $rate = round($transitive / $chains * 100);
            $mean = array_sum($y) / $n;
            $var = 0;
            foreach ($y as $v) $var += ($v - $mean) ** 2;
            $cv = round(sqrt($var / $n) / (abs($mean) + 1e-8), 4);
            echo "  ❌ $pred: НЕ транзитивно (CV=$cv, $transitive/$chains=$rate%)\n";
        }
    } else {
        echo "  ⚠️ $pred: недостаточно цепочек\n";
    }
}

// ТЕСТ 2: Симметрия → CV→0?
echo "\n─── SYMMETRY ───\n";
foreach (['links_to'] as $pred) {
    $predFacts = array_filter($facts, fn($f) => $f['p'] === $pred);
    $pairs = [];
    foreach ($predFacts as $f) {
        $pairs[$f['s'] . '|||' . $f['o']] = true;
    }
    
    $symmetric = 0;
    $asymmetric = 0;
    foreach ($pairs as $key => $_) {
        [$a, $b] = explode('|||', $key);
        $revKey = $b . '|||' . $a;
        if (isset($pairs[$revKey])) $symmetric++;
        else $asymmetric++;
    }
    
    $total = $symmetric + $asymmetric;
    if ($total > 0) {
        $rate = round($symmetric / $total * 100);
        if ($symmetric === $total) {
            echo "  ✅ $pred: СИММЕТРИЧНО (CV=0, $total пар)\n";
        } elseif ($asymmetric === $total) {
            echo "  ✅ $pred: АСИММЕТРИЧНО (CV=0, $total пар)\n";
        } else {
            echo "  ❌ $pred: смешанно ($symmetric симм / $asymmetric асимм = $rate%)\n";
        }
    }
}

// ТЕСТ 3: Иерархия is_a — есть ли циклы?
echo "\n─── HIERARCHY (is_a cycles) ───\n";
$isaFacts = array_filter($facts, fn($f) => $f['p'] === 'is_a');
$graph = [];
foreach ($isaFacts as $f) {
    $graph[$f['s']][] = $f['o'];
}

// Проверка циклов (DFS)
function hasCycle(array $graph, string $node, array &$visited = [], array &$stack = []): bool {
    $visited[$node] = true;
    $stack[$node] = true;
    foreach (($graph[$node] ?? []) as $neighbor) {
        if (!isset($visited[$neighbor]) && hasCycle($graph, $neighbor, $visited, $stack)) {
            return true;
        } elseif (isset($stack[$neighbor])) {
            return true;
        }
    }
    $stack[$node] = false;
    return false;
}

$cycles = 0;
foreach (array_keys($graph) as $node) {
    $visited = []; $stack = [];
    if (hasCycle($graph, $node, $visited, $stack)) $cycles++;
}

echo "  Nodes: " . count($graph) . " | Edges: " . count($isaFacts) . "\n";
if ($cycles === 0) {
    echo "  ✅ No cycles — valid hierarchy (CV=0 for acyclicity)\n";
} else {
    echo "  ❌ $cycles cycles detected — CV>0\n";
}

// ТЕСТ 4: Кластеризация links_to — CV=0 для плотных кластеров?
echo "\n─── CLUSTERS (links_to density) ───\n";
$linkFacts = array_filter($facts, fn($f) => $f['p'] === 'links_to');
if ($linkFacts) {
    $linkGraph = [];
    foreach ($linkFacts as $f) {
        $linkGraph[$f['s']][$f['o']] = true;
    }
    
    // Для каждой пары (A,B): есть ли связь A→B? → бинарный признак
    // Сгруппируем по дате заметки
    $byDate = [];
    foreach ($wikilinks as $f) {
        $date = explode('.', $f['s'])[0] ?? 'unknown';
        $byDate[$date][] = $f;
    }
    
    // Проверим: в пределах одной заметки, ссылки образуют плотный граф?
    $intraNote = 0;
    $dense = 0;
    foreach ($byDate as $date => $links) {
        if (count($links) < 3) continue;
        $nodes = array_unique(array_merge(
            array_column($links, 's'),
            array_column($links, 'o')
        ));
        $n = count($nodes);
        $possibleEdges = $n * ($n - 1);
        $actualEdges = count($links);
        $density = $possibleEdges > 0 ? $actualEdges / $possibleEdges : 0;
        
        if ($density > 0.5) $dense++;
        $intraNote++;
    }
    
    $cv = $intraNote > 0 ? round(($intraNote - $dense) / $intraNote, 3) : 0;
    if ($dense > 0) {
        echo "  Dense notes: $dense/$intraNote\n";
        echo "  CV=" . round(abs($cv), 3) . " (чем ближе к 0, тем плотнее кластеры)\n";
    } else {
        echo "  No dense clusters found\n";
    }
}

// ═══ ТЕСТ 5: Прямая проверка — берём факт, проверяем обратный ═══
echo "\n─── INVERSE TEST ───\n";
$isaSample = array_slice(array_values($isaFacts), 0, 50);
$inverseConfirmed = 0;
$inverseRejected = 0;

foreach ($isaSample as $fact) {
    // A is_a B → существует ли B is_a A? (должно быть НЕТ для строгой иерархии)
    $hasInverse = false;
    foreach ($isaFacts as $f2) {
        if ($f2['s'] === $fact['o'] && $f2['o'] === $fact['s']) {
            $hasInverse = true;
            break;
        }
    }
    if ($hasInverse) $inverseConfirmed++;
    else $inverseRejected++;
}

echo "  Tested: " . count($isaSample) . " is_a facts\n";
if ($inverseConfirmed === 0) {
    echo "  ✅ is_a СТРОГО ИЕРАРХИЧНО — обратных связей нет (CV=0)\n";
} else {
    $rate = round($inverseConfirmed / count($isaSample) * 100);
    echo "  ❌ $inverseConfirmed обратных связей ($rate%) — есть циклы или ошибки\n";
}

echo "\nDone.\n";
