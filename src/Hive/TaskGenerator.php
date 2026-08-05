<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * TaskGenerator — генерация задач (foraged + базовые + cross-pair).
 *
 * Извлечён из Hive::getTasks(). D15: делегирует TaskManager для базовых задач.
 */
class TaskGenerator
{
    /** S2.7: Maximum cross-pair tasks from generator (bounds O(N²) memory). */
    private int $maxCrossPair = 200;  // cross-pairing включён с variance-фильтром (05.08)

    /**
     * Сгенерировать задачи: базовые (TaskManager) + cross-pair + foraged.
     *
     * @param array $foragedTasksGlobal глобальный список foraged задач
     * @param array $crossTasks дополнительные cross-pair задачи (не используется — TaskGenerator сам делает cross-pair)
     * @return array<int, array{name: string, data: array, domain: string}>
     */
    public function generate(
        array $foragedTasksGlobal,
        array $crossTasks = [],
        ?\BeeSwarm\Text\SentenceRegistry $sentenceRegistry = null,
        ?\BeeSwarm\Text\CorpusVocabulary $corpusVocab = null,
        int $currentTaskCount = 0,
    ): array {
        // Базовые синтетические задачи — делегируем TaskManager
        $tm = new TaskManager();
        $tasks = $tm->getBaseTasks();

        // S2.7: Lazy cross-pairing — consume generator with bound
        $cross = [];
        $crossCount = 0;
        foreach ($this->crossPairTasks($foragedTasksGlobal) as $crossTask) {
            $cross[] = $crossTask;
            $crossCount++;
            if ($crossCount >= $this->maxCrossPair) break;
        }
        $tasks = array_merge($tasks, $cross);

        // Cloze tasks (limited)
        if ($sentenceRegistry && $corpusVocab && $currentTaskCount < 40) {
            $cloze = $this->clozeTasks($sentenceRegistry, $corpusVocab);
            $tasks = array_merge($tasks, $cloze);
        }

        return array_merge($tasks, $foragedTasksGlobal);
    }

    /**
     * Cross-pairing: текстовые атомы → X/y пары (lazy generator).
     *
     * @return \Generator<array{name: string, data: array, domain: string}>
     */
    private function crossPairTasks(array $foragedTasksGlobal): \Generator
    {
        $txtTasks = array_filter($foragedTasksGlobal, fn ($t) => str_starts_with($t['name'] ?? '', 'foraged_txt_'));
        if (count($txtTasks) < 2) {
            return;
        }

        $atoms = [];
        foreach ($txtTasks as $t) {
            $name = $t['name'];
            foreach ($t['data'] as $row) {
                $val = $row[0] ?? null;
                if ($val !== null) {
                    $atoms[$name][] = (float) $val;
                }
            }
        }

        yield from \BeeSwarm\Core\TextAtomCrossPairer::crossPair($atoms, 'text_pairs');
    }

    /**
     * Cloze-задачи: предсказание пропущенного слова.
     */
    private function clozeTasks(
        \BeeSwarm\Text\SentenceRegistry $sr,
        \BeeSwarm\Text\CorpusVocabulary $cv,
    ): array {
        $tasks = [];
        $n = min($sr->count(), 50);
        $stopWords = ['i', 'v', 'na', 's', 'ne', 'ili', 'no', 'a'];
        for ($i = 0; $i < $n; $i++) {
            $s = $sr->get($i);
            if (! $s || count($s['token_ids']) < 3) {
                continue;
            }
            foreach ($s['token_ids'] as $pos => $tid) {
                $w = $cv->word($tid);
                if (! $w || in_array($w, $stopWords)) {
                    continue;
                }
                $d = [[$i, $pos, $tid, 1.0]];
                for ($j = 0; $j < 3; $j++) {
                    $r = mt_rand(1, $cv->size());
                    if ($r !== $tid) {
                        $d[] = [$i, $pos, $r, 0.0];
                    }
                }
                $tasks[] = [
                    'name' => "cloze_{$i}_{$pos}",
                    'data' => $d,
                    'domain' => 'cloze',
                ];
                break;
            }
        }
        return $tasks;
    }

    /**
     * GEN_ compose-задачи: пары grammar-операций → синтетические данные.
     * Извлечён из Hive::getTasks().
     */
    public function createComposeTasks(): array
    {
        // RngIsolation — детерминизм GEN_ с восстановлением энтропии
        // (E1.3-fix: попадает под assertClean + pre-commit grep-запрет)
        $guard = \BeeSwarm\Infra\RngIsolation::deterministicSeed(42);
        $g = new \BeeSwarm\Core\Grammar();
        $grammarOps = $g->all();
        if (count($grammarOps) < 2) {
            $guard->restore();
            return [];
        }

        $tasks = [];
        $count = 0;
        foreach ($grammarOps as $outer) {
            foreach ($grammarOps as $inner) {
                if ($outer === $inner || $count >= 10) {
                    break 2;
                }
                if (! \BeeSwarm\Core\AtomRegistry::isUnary($outer)) {
                    continue;
                }
                $data = [];
                for ($i = 0; $i < 6; $i++) {
                    $x = mt_rand(-10, 10);
                    $y = mt_rand(-10, 10);
                    $v1 = \BeeSwarm\Core\AtomRegistry::isBinary($inner)
                        ? \BeeSwarm\Core\AtomRegistry::apply($inner, (float) $x, (float) $y)
                        : \BeeSwarm\Core\AtomRegistry::apply($inner, (float) $x);
                    if ($v1 === null || is_nan($v1) || is_infinite($v1)) {
                        continue;
                    }
                    $v2 = \BeeSwarm\Core\AtomRegistry::apply($outer, $v1);
                    if ($v2 === null || is_nan($v2) || is_infinite($v2)) {
                        continue;
                    }
                    $data[] = [(float) $x, (float) $y, $v2];
                }
                if (count($data) >= 3) {
                    $tasks[] = [
                        'name' => "GEN_{$outer}_{$inner}",
                        'data' => $data,
                        'domain' => 'generated',
                    ];
                    $count++;
                }
            }
        }

        $guard->restore();
        return $tasks;
    }
}
