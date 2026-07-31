<?php
declare(strict_types=1);

/**
 * Расширенная диагностика ВСЕХ классов задач (01.08.2026).
 *
 * Проверяем не только metrics-пары, но и:
 * 1. foraged_txt_* — текстовые атомы из vault (формат, кросс-парность)
 * 2. foraged_semantic — KG-факты
 * 3. cloze — текстовое предсказание
 * 4. grammar_ops — какие атомы реально рождены из vault
 * 5. Форматный контракт: [feature, target] vs [target]
 *
 * Ноль изменений в src/. SWARM_DB_PATH=data/swarm.db (production).
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Forager\DataSelfGenerator;
use BeeSwarm\Forager\Forager;
use BeeSwarm\Infra\Database;
use BeeSwarm\Math\CvCalculator;

// ======== 1: forager — что лезет из vault ========
echo "══════════════════════════════════════\n";
echo "КЛАСС 1: FORAGER (сканирует vault 5000 файлов)\n";
echo "══════════════════════════════════════\n";

$lair = getenv('HOME') . '/Documents/the_lair';
$forager = new Forager();
$foraged = $forager->scanWithAccumulator([$lair => 1]);

if (empty($foraged)) {
    echo "НЕТ foraged-задач. Возможные причины:\n";
    echo "  - StreamingAccumulator требует накопления (порог tMin)\n";
    echo "  - Forager::scanWithAccumulator возвращает пусто при первом вызове\n\n";

    // Попробуем прямой scan
    echo "Пробуем Forager::scan()...\n";
    $foraged = $forager->scan([$lair => 1]);
    echo "  scan() вернул: " . count($foraged) . " задач\n";
    if (! empty($foraged)) {
        echo "  Домены:\n";
        $d = array_count_values(array_column($foraged, 'domain'));
        foreach ($d as $dom => $cnt) echo "    $dom: $cnt\n";
    }
} else {
    echo "scanWithAccumulator вернул: " . count($foraged) . " задач\n";
}

// ======== 2: grammar_ops — из чего состоит грамматика ========
echo "\n══════════════════════════════════════\n";
echo "КЛАСС 2: GRAMMAR_OPS (атомы в грамматике)\n";
echo "══════════════════════════════════════\n";

$g = new Grammar();
echo "Всего ops: " . $g->count() . "\n";
$all = $g->all();

$textAtoms = array_filter($all, fn ($op) => str_starts_with($op, 'foraged_txt_'));
$txtMatch  = array_filter($all, fn ($op) => str_starts_with($op, 'txt_') && ! str_starts_with($op, 'txt_0'));
$semantic  = array_filter($all, fn ($op) => in_array($op, ['is_a', 'has', 'relates_to', 'can']));
$numeric   = array_filter($all, fn ($op) => !str_starts_with($op, 'foraged_txt_') && !str_starts_with($op, 'txt_') && !in_array($op, ['is_a', 'has', 'relates_to', 'can']));

echo "  foraged_txt_* (текстовые из vault): " . count($textAtoms) . "\n";
echo "  txt_* (прочие текстовые): " . count($txtMatch) . "\n";
echo "  semantic (is_a/has/...): " . count($semantic) . "\n";
echo "  numeric (+,−,×,...): " . count($numeric) . "\n";

if (count($textAtoms) > 0) {
    echo "  Примеры текстовых атомов:\n";
    $samples = array_slice(array_keys($textAtoms), 0, 5);
    foreach ($samples as $sa) echo "    $sa\n";
}

// ======== 3: задачи из DataSelfGenerator (ВСЕ, не только метрики) ========
echo "\n══════════════════════════════════════\n";
echo "КЛАСС 3: DataSelfGenerator (fromLaws + fromMetrics)\n";
echo "══════════════════════════════════════\n";

$gen = new DataSelfGenerator();
$metricsTasks = $gen->fromMetrics();
$lawTasks = $gen->fromLaws();
echo "fromMetrics(): " . count($metricsTasks) . " задач (пары метрик)\n";
echo "fromLaws(): " . count($lawTasks) . " задач (перенос законов между доменами)\n";
if (! empty($lawTasks)) {
    echo "  Законов в БД для переноса: ";
    $laws = Database::get()->query('SELECT name, domain FROM laws WHERE cv < 0.05 LIMIT 5')->fetchAll();
    foreach ($laws as $l) echo $l['name'] . '(' . $l['domain'] . ') ';
    echo "\n";
}

// ======== 4: форматный контракт — для каждого класса ========
echo "\n══════════════════════════════════════\n";
echo "КЛАСС 4: ФОРМАТНЫЙ КОНТРАКТ (X/y для Search::find)\n";
echo "══════════════════════════════════════\n";

$domainsChecked = [];
// metrics
foreach ($metricsTasks as $t) {
    $data = $t['data'] ?? [];
    $nCols = ! empty($data[0]) ? count($data[0]) : 0;
    $domain = $t['domain'] ?? 'unknown';
    if (isset($domainsChecked[$domain])) continue;
    $domainsChecked[$domain] = true;
    echo "  domain='$domain' (" . count($data) . " rows): $nCols columns\n";
    if ($nCols > 0) echo "    format: [" . implode(', ', array_map(fn ($i) => "col$i", range(0, $nCols-1))) . "]\n";
    if ($nCols === 1) echo "    ⚠️  ОДНА колонка → X пустой → Search::find возвращает empty!\n";
}

// ======== 5: ТЕКСТОВЫЕ атомы — есть ли они в задачах? ========
echo "\n══════════════════════════════════════\n";
echo "КЛАСС 5: ТЕКСТОВЫЕ АТОМЫ — формат задач\n";
echo "══════════════════════════════════════\n";

// Ищем в getTasks() — через рефлексию на Hive
// Но вместо этого посмотрим структуру grammar_ops для текстовых
$txtCount = 0;
foreach ($all as $opName) {
    if (str_starts_with($opName, 'foraged_txt_')) {
        $txtCount++;
        if ($txtCount <= 3) {
            // Посмотрим definition (может содержать формат)
            try {
                $def = Database::get()
                    ->prepare('SELECT definition FROM grammar_ops WHERE name = ?')
                    ->execute([$opName])->fetchColumn();
                echo "  $opName: " . ($def ?: '(нет definition)') . "\n";
            } catch (\Throwable) {
                echo "  $opName: (ошибка чтения)\n";
            }
        }
    }
}
echo "Всего текстовых атомов в grammar_ops: $txtCount\n";

// ======== 6: СТРУКТУРА VAULT — что за файлы ========
echo "\n══════════════════════════════════════\n";
echo "КЛАСС 6: СТРУКТУРА VAULT\n";
echo "══════════════════════════════════════\n";

if (is_dir($lair)) {
    $rit = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($lair, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $byExt = [];
    $byDir = [];
    foreach ($rit as $f) {
        $ext = $f->getExtension();
        $byExt[$ext] = ($byExt[$ext] ?? 0) + 1;
        $dir = str_replace($lair . '/', '', dirname($f->getPathname()));
        $topDir = explode('/', $dir)[0];
        $byDir[$topDir] = ($byDir[$topDir] ?? 0) + 1;
    }
    echo "Файлы по расширениям:\n";
    arsort($byExt);
    foreach ($byExt as $ext => $cnt) {
        echo "  .$ext: $cnt\n";
    }
    echo "\nФайлы по верхним директориям:\n";
    arsort($byDir);
    foreach ($byDir as $d => $cnt) {
        echo "  $d/: $cnt\n";
    }
} else {
    echo "  $lair не найден\n";
}

// ======== 7: KG-факты — откуда и что ========
echo "\n══════════════════════════════════════\n";
echo "КЛАСС 7: KNOWLEDGE GRAPH (семантические факты из vault)\n";
echo "══════════════════════════════════════\n";

$kgTotal = (int) Database::get()->query('SELECT COUNT(*) FROM knowledge_graph')->fetchColumn();
$kgPredicates = Database::get()->query('SELECT predicate, COUNT(*) c FROM knowledge_graph GROUP BY predicate ORDER BY c DESC LIMIT 8')->fetchAll();
$kgDomains = Database::get()->query("SELECT DISTINCT SUBSTR(object, INSTR(object, ':')+1) AS obj_type, COUNT(*) c FROM knowledge_graph WHERE object LIKE '%:%' GROUP BY obj_type ORDER BY c DESC LIMIT 8")->fetchAll();

echo "Всего фактов в KG: $kgTotal\n";
echo "По предикатам:\n";
foreach ($kgPredicates as $r) echo "  {$r['predicate']}: {$r['c']}\n";
if (! empty($kgDomains)) {
    echo "По типам объектов:\n";
    foreach ($kgDomains as $r) echo "  {$r['obj_type']}: {$r['c']}\n";
}

// ======== 8: СВОДКА — что упущено? ========
echo "\n══════════════════════════════════════\n";
echo "СВОДКА: ЧТО СИСТЕМА ВИДИТ, А МЫ НЕ ДИАГНОСТИРОВАЛИ\n";
echo "══════════════════════════════════════\n";

echo "✅ metrics (35) — диагностированы (corr, R², minCV)\n";
echo "✅ laws (6 arithmetic) — синтетика, не реальные данные\n";
echo "✅ grammar_ops ($txtCount txt + " . count($numeric) . " num + " . count($semantic) . " sem) — подсчитаны\n";
echo "✅ KG (" . $kgTotal . " фактов) — подсчитаны\n";
echo "⬜ doDiscoverTick — формат X/y для foraged_txt_* (E1-FIX cross-pairing?)\n";
echo "⬜ cloze — эффективность (error rate < 0.7?)\n";
echo "⬜ forager — реальное количество задач на продакшене (нужен запуск daemon)\n";
echo "\nЧто НЕ сделано (требует запуска daemon):\n";
echo "  - Hive::getTasks() snapshots по доменам\n";
echo "  - cloze error rate distribution\n";
echo "  - foraged_txt_* → doDiscoverTick → X empty? формат контракта\n";
echo "  - реальный лог DISCOVERY / DUPLICATE / PLATEAU за последние запуски\n";

echo "\nДиагностика завершена.\n";
