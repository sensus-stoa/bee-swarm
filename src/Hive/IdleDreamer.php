<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\ComposeBridge;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\Grammar;

/**
 * IdleDreamer — кросс-доменный поиск в idle-тактах (§2.5-децим).
 *
 * Phase 1: когда foundAny=false, IdleDreamer пытается compose
 * с РАСШИРЕННОЙ грамматикой (все ops из БД, не только BASE_OPS)
 * на всех доступных задачах. Если находит — возвращает открытие.
 */
class IdleDreamer
{
    /**
     * Полный цикл idle dreaming: подготовка задач + поиск.
     */
    public static function tick(array $tasks, array $grammarOps, float $cvThreshold = 0.01): ?array
    {
        $dreamTasks = self::prepareTasks($tasks);
        if (empty($dreamTasks)) {
            return null;
        }
        $dreamer = new self();
        return $dreamer->dream($dreamTasks, $cvThreshold, $grammarOps);
    }

    /**
     * Подготовить задачи для dreaming: извлечь X,y из data, отфильтровать insufficient.
     *
     * @param array $tasks сырые задачи из getTasks()
     * @return array<int, array{name: string, domain: string, X: array, y: array}>
     */
    public static function prepareTasks(array $tasks): array
    {
        $dreamTasks = [];
        foreach ($tasks as $t) {
            $data = $t['data'] ?? [];
            if (! self::isDreamable($data)) {
                continue;
            }
            $X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
            $y = array_column($data, count($data[0]) - 1);
            $nFeat = count($X[0] ?? []);
            if (count($y) < max(10, $nFeat * 5)) {
                continue;
            }
            $dreamTasks[] = [
                'name' => $t['name'] ?? 'unknown',
                'domain' => $t['domain'] ?? 'unknown',
                'X' => $X,
                'y' => $y,
                'raw_data' => $data,
            ];
        }
        return $dreamTasks;
    }

    /**
     * Фильтр dream-пригодности: data непуста, >=2 колонок, все ячейки
     * числовые. F2 (agent-review 29.09): txt-задачи с >=2 колонками
     * проходят широтный фильтр → PHP 8 coercion ('foo'+0=0, warning)
     * даёт одинаковые нулевые векторы всем формам → ложная конгруэнтность.
     *
     * @param array $data
     */
    private static function isDreamable(array $data): bool
    {
        return ! empty($data)
            && count($data[0] ?? []) >= 2
            && self::numericRows($data);
    }

    /**
     * F2-гвард: все ячейки данных числовые (int/float/numeric-string).
     * Текстовые задачи не доходят до compose — PHP-коэрция тихо превращает
     * слова в нули и порождает ложные конгруэнтности.
     *
     * @param array $data
     */
    private static function numericRows(array $data): bool
    {
        foreach ($data as $row) {
            foreach ($row as $cell) {
                if (! is_numeric($cell)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Попытаться найти закон через расширенный compose на всех задачах.
     *
     * @param array $tasks массив ['name' => string, 'domain' => string, 'X' => array, 'y' => array]
     * @param float $cvThreshold порог CV для открытия
     * @param string[] $grammarOps операции грамматики для compose (per-bee + BASE_OPS)
     * @return array{atom: string, cv: float, mode: string, domain: string, task_name: string}|null открытие или null
     */
    public function dream(array $tasks, float $cvThreshold = 0.01, array $grammarOps = []): ?array
    {
        if (empty($tasks)) {
            return null;
        }

        $grammarOps = $grammarOps !== [] ? $grammarOps : (new Grammar())->all();
        if (count($grammarOps) < 2) {
            return null;
        }

        foreach ($tasks as $task) {
            $final = $this->tryTask($task, $cvThreshold, $grammarOps);
            if ($final !== null) {
                return $final;
            }
        }

        return null;
    }

    /**
     * Попытка dream-открытия на одной задаче.
     *
     * @param array<string, mixed> $task
     * @param string[] $grammarOps
     *
     * @return array<string, mixed>|null
     */
    private function tryTask(array $task, float $cvThreshold, array $grammarOps): ?array
    {
        $X = $task['X'];
        $y = $task['y'];
        if (count($y) < max(10, count($X[0] ?? []) * 5)) {
            return null;
        }

        $found = AtomRegistry::discoverCompose($X, $y, $grammarOps, $cvThreshold);
        if (empty($found)) {
            return null;
        }

        // DREAM-CONGRUENCE (29.09): мост compose-нотации в язык роя
        // (unmappable → fail-closed) + data/X/y для детектора,
        // классификатора и V-спавна (единая с search экономика).
        // Round-trip отказ моста → следующая задача (F1b).
        return $this->finalizeResult($found[0], $task, $X, $y);
    }

    /**
     * DREAM-CONGRUENCE (29.09): финализация открытия — мост нотации +
     * перенос контекста задачи в результат.
     *
     * @param array<string, mixed> $best
     * @param array{name: string, domain: string, X: array, y: array, raw_data: array} $task
     * @param list<list<float>> $X
     * @param list<float> $y
     *
     * @return array<string, mixed>|null
     */
    private function finalizeResult(array $best, array $task, array $X, array $y): ?array
    {
        // Нотационная дыра: функциональная форма 'sq(+)' не вычисляется
        // evaluator'ом → детектор/классификатор/награда мертвы. Мост по
        // семантике AtomProvider::discoverCompose (probe 29.09).
        $infix = ComposeBridge::toInfix((string) ($best['atom'] ?? ''));
        if ($infix !== null) {
            // F1b/F3 round-trip гейт (agent-review 29.09): мост-выход обязан
            // пройти parse+normalize+eval ДО записи — иначе скрытый TypeError
            // в normalize (sqrt/abs-суффиксы) или мусор уйдёт в RecordKeeper.
            if (! self::roundTripOk($infix, $X, $y)) {
                return null;
            }
            $best['atom'] = $infix;
        }
        $best['data'] = $task['raw_data'] ?? [];
        $best['X'] = $X;
        $best['y'] = $y;
        $best['mode'] = 'dream';
        $best['domain'] = $task['domain'] ?? 'unknown';
        $best['task_name'] = $task['name'] ?? 'unknown';

        return $best;
    }

    /**
     * Round-trip валидация мост-выхода: parse+normalize не падают
     * (TypeError → false), eval на данных не null. Fail-closed.
     *
     * @param list<list<float>> $X
     * @param list<float> $y
     */
    private static function roundTripOk(string $infix, array $X, array $y): bool
    {
        try {
            ExpressionNormalizer::normalize($infix);
        } catch (\TypeError) {
            return false;
        }

        return ExpressionEvaluator::evaluateFormula($infix, $X) !== null;
    }
}
