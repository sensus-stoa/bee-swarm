<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Infra\Database;

/**
 * V0.14 WU-2 (verification-economy): исполнитель V-задач.
 *
 * resample: срез train (data_json) → Search::find → подтверждение при
 * LawShape(находки) == law_shape задачи (T4-маска).
 * inverted: поиск на −y; подтверждение при совпадении маски И anchor-статистика
 * (median pred / median y закона) не расходится более чем в ANCHOR_RATIO_MAX
 * раз (иначе ANOMALY — противоречие углубляется).
 *
 * Память (ops-урок Demo #3): train-only срез из data_json (cap 30 при спавне),
 * beam ≤ BEAM_MAX — параметры среды, не механизмы. Исполнитель НЕ блокирует
 * discovery: любой сбой = inconclusive, не исключение.
 */
final class VerificationExecutor
{
    /**
     * Максимальный beam для V-задач (дешевле полного поиска).
     */
    public const BEAM_MAX = 15;

    /**
     * Memory-кап поиска V-задачи (критерий ВАЖНО Demo #3; env VVERIFY_MEMORY_LIMIT).
     */
    public const MEMORY_LIMIT = '512M';

    /**
     * Медиана ниже эпсилона = вырожденный срез для anchor-гейта (премортем #3).
     */
    public const ANCHOR_MEDIAN_EPS = 1e-9;

    /**
     * Exact-порог V-поиска: та же линейка, что у открытия (паритет WU-1);
     * env VVERIFY_CV_TRAIN_MAX.
     */
    public const CV_TRAIN_MAX = 0.05;

    /**
     * Anchor-граница спеки: не более чем в 2 раза.
     */
    public const ANCHOR_RATIO_MAX = 2.0;

    /**
     * Минимум данных задачи (tMin для 1 фичи = 10).
     */
    public const BOOTSTRAP_MIN_ROWS = 10;

    /**
     * Исходы V-задачи (статус в verification_tasks).
     */
    public const OUTCOME_CONFIRMED = 'confirmed';

    public const OUTCOME_REFUTED = 'refuted';

    public const OUTCOME_INCONCLUSIVE = 'inconclusive';

    public const OUTCOME_ANOMALY = 'anomaly';

    /**
     * Канал лога: инъекция (Hive::log) или stderr.
     */
    private ?\Closure $logger;

    public function __construct(?\Closure $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Resample-задача: подтверждение = T4-маска совпала на срезе train.
     *
     * @return array{outcome: string, anomaly: bool, formula: ?string, cv: ?float}
     */
    public function runResample(array $vtask, array $X, array $y): array
    {
        $found = $this->findBest($vtask, $X, $y);
        if ($found === null) {
            $this->logTask('VINCONCLUSIVE', $vtask, 'no_form');

            return $this->verdict(
                self::OUTCOME_INCONCLUSIVE,
                false,
                null,
                null,
            );
        }
        [$formula, $cv] = $found;

        if (! $this->shapeMatches((string) $vtask['law_shape'], $formula)) {
            $this->logTask('VREFUTED', $vtask, "shape formula={$formula}");

            return $this->verdict(
                self::OUTCOME_REFUTED,
                false,
                $formula,
                $cv,
            );
        }

        $this->logTask('VCONFIRMED', $vtask, "formula={$formula} cv=" . number_format($cv, 4));

        return $this->verdict(self::OUTCOME_CONFIRMED, false, $formula, $cv);
    }

    /**
     * Inverted-задача: поиск на −y + anchor-гейт. ANOMALY = refuted.
     *
     * @return array{outcome: string, anomaly: bool, formula: ?string, cv: ?float}
     */
    public function runInverted(array $vtask, array $X, array $y): array
    {
        $negY = array_map(static fn ($v): float => -1.0 * (float) $v, $y);
        $found = $this->findBest($vtask, $X, $negY);
        if ($found === null) {
            // На −y формы нет: знак закона не опровергнут — не подтверждаю и не опровергаю.
            $this->logTask('VINCONCLUSIVE', $vtask, 'no_form_inverted');

            return $this->verdict(
                self::OUTCOME_INCONCLUSIVE,
                false,
                null,
                null,
            );
        }
        [$formula, $cv] = $found;

        if (! $this->shapeMatches((string) $vtask['law_shape'], $formula)) {
            $this->logTask('VREFUTED', $vtask, "inverted_shape formula={$formula}");

            return $this->verdict(
                self::OUTCOME_REFUTED,
                true,
                $formula,
                $cv,
            );
        }

        return $this->anchorGate($vtask, $formula, $cv, $X, $y);
    }

    /**
     * Anchor-гейт инверсной ветки: null ratio = inconclusive,
     * ratio > ANCHOR_RATIO_MAX = ANOMALY, иначе подтверждение.
     */
    private function anchorGate(array $vtask, string $formula, float $cv, array $X, array $y): array
    {
        $ratio = $this->anchorRatio($formula, (string) $vtask['law_formula'], $X, $y);
        if ($ratio === null) {
            $this->logTask('VINCONCLUSIVE', $vtask, "anchor_unevaluable formula={$formula}");

            return $this->verdict(self::OUTCOME_INCONCLUSIVE, false, $formula, $cv);
        }
        // Спека «не расходится более чем в 2 раза» — ДВУСТОРОННЯЯ: схлопнувшаяся
        // амплитуда (ratio < 1/2) опровергает так же, как разросшаяся (> 2).
        if ($ratio > self::ANCHOR_RATIO_MAX || $ratio < 1.0 / self::ANCHOR_RATIO_MAX) {
            $this->logTask('VREFUTED', $vtask, 'ANOMALY anchor_ratio=' . number_format($ratio, 2));

            return $this->verdict(self::OUTCOME_ANOMALY, true, $formula, $cv);
        }

        $this->logTask('VCONFIRMED', $vtask, 'anchor_ratio=' . number_format($ratio, 2));

        return $this->verdict(self::OUTCOME_CONFIRMED, false, $formula, $cv);
    }

    /**
     * Единый контракт исхода V-задачи.
     */
    private function verdict(string $outcome, bool $anomaly, ?string $formula, ?float $cv): array
    {
        return [
            'outcome' => $outcome,
            'anomaly' => $anomaly,
            'formula' => $formula,
            'cv' => $cv,
        ];
    }

    /**
     * Anchor-статистика: median(|pred|) / median(|y закона|) после снятия знака.
     * null = формула не вычислима (нет констант в грамматике и т.п.) — честное
     * «не могу оценить», не ANOMALY.
     */
    public function anchorRatio(string $formula, string $lawFormula, array $X, array $y): ?float
    {
        $rows = $this->rowsOf($X, $y);
        $preds = ExpressionEvaluator::evaluateFormula($formula, $rows);
        $lawPreds = ExpressionEvaluator::evaluateFormula($lawFormula, $rows);
        if ($preds === null || $lawPreds === null) {
            return null;
        }
        $medLaw = self::median(array_map('abs', $lawPreds));
        // Премортем #3: вырожденная медиана → ratio взрывается → штампованные
        // ANOMALY. Epsilon-гвард: не могу оценить масштаб → inconclusive (null).
        if ($medLaw < self::ANCHOR_MEDIAN_EPS) {
            return null;
        }

        return self::median(array_map('abs', $preds)) / $medLaw;
    }

    /**
     * Пул pending-задач домена → исполнение с батч-лимитом (премортем #5:
     * thundering herd первого прогона). Возвращает число исполненных.
     */
    public function runPendingVerificationTasks(string $domain, int $limit): int
    {
        $stmt = Database::get()->prepare(
            "SELECT * FROM verification_tasks
             WHERE status = 'pending' AND domain = ?
             ORDER BY id ASC LIMIT ?"
        );
        $stmt->bindValue(1, $domain, \PDO::PARAM_STR);
        $stmt->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();
        $executed = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->executeFromQueue($row);
            $executed++;
        }

        return $executed;
    }

    /**
     * Одно задание из очереди: данные из data_json, исход → status.
     * Возвращает false только на инфраструктурном сбое статуса.
     */
    private function executeFromQueue(array $row): bool
    {
        try {
            $data = $this->decodePayload($row);
            if ($data === null) {
                $this->setStatus((int) $row['id'], self::OUTCOME_INCONCLUSIVE);

                return true;
            }
            $X = array_map(static fn (array $r): array => array_slice($r, 0, -1), $data);
            $y = array_map(static fn (array $r): float => (float) $r[count($r) - 1], $data);

            $result = $this->dispatchByKind($row, $X, $y);
            if ($result === null) {
                $this->setStatus((int) $row['id'], self::OUTCOME_INCONCLUSIVE);

                return true;
            }
            $status = $result['anomaly'] ? self::OUTCOME_ANOMALY : $result['outcome'];
            $this->setStatus((int) $row['id'], $status);

            return true;
        } catch (\Throwable $e) {
            // F6: сбой исполнения ≠ вердикт о задаче — оставляю pending
            // (retry-логика/кап попыток — WU-3), grep-уемый лог.
            $this->logTask('VERROR', $row, 'exec ' . $e->getMessage());

            return false;
        }
    }

    /**
     * F8: whitelist роутинг kind — неизвестный kind не уходит тихо в inverted.
     */
    private function dispatchByKind(array $row, array $X, array $y): ?array
    {
        $kind = (string) $row['kind'];
        if (str_starts_with($kind, 'resample')) {
            return $this->runResample($row, $X, $y);
        }
        if ($kind === VerificationTaskSource::KIND_INVERTED
                || $kind === VerificationTaskSource::KIND_INVERTED_RESEARCH
            ) {
            return $this->runInverted($row, $X, $y);
        }
        $this->logTask('VINCONCLUSIVE', $row, "unknown_kind={$kind}");

        return null;
    }

    /**
     * Декод data_json с коррапт-детектом (премортем #5): битый JSON —
     * VCORRUPT-маркер, не безликий inconclusive. null = нет пригодных данных.
     */
    private function decodePayload(array $row): ?array
    {
        $raw = (string) ($row['data_json'] ?? '');
        $data = json_decode($raw, true);
        if ($raw !== '' && json_last_error() !== JSON_ERROR_NONE) {
            $this->logTask('VCORRUPT', $row, 'data_json ' . json_last_error_msg());

            return null;
        }
        if (! is_array($data) || count($data) < self::BOOTSTRAP_MIN_ROWS) {
            $this->logTask('VINCONCLUSIVE', $row, 'no_data');

            return null;
        }

        return $data;
    }

    private function setStatus(int $id, string $status): void
    {
        Database::get()->prepare('UPDATE verification_tasks SET status = ? WHERE id = ?')
            ->execute([$status, $id]);
    }

    /**
     * Guard-пара окружения поиска: beam (премортем #2: restore-old, не unset)
     * + memory-кап (критерий ВАЖНО Demo #3). Возвращает closure-restore.
     */
    private function applySearchEnv(string $beam): \Closure
    {
        $prevBeam = getenv('SEARCH_BEAM_K');
        putenv("SEARCH_BEAM_K={$beam}");
        $prevLimit = (string) ini_get('memory_limit');
        $memCap = getenv('VVERIFY_MEMORY_LIMIT') ?: self::MEMORY_LIMIT;
        ini_set('memory_limit', $memCap);

        return function () use ($prevBeam, $prevLimit): void {
            if ($prevBeam === false) {
                putenv('SEARCH_BEAM_K');
            } else {
                putenv("SEARCH_BEAM_K={$prevBeam}");
            }
            ini_set('memory_limit', $prevLimit);
        };
    }

    /**
     * Поиск на срезе: ограниченный beam, порог exact (V-задача проверяет
     * воспроизводимость формы, не ищет аппроксимации).
     *
     * @return array{0: string, 1: float}|null [formula, cv]
     */
    private function findBest(array $vtask, array $X, array $y): ?array
    {
        $grammar = new Grammar();
        $beam = getenv('VVERIFY_BEAM_K') ?: (string) self::BEAM_MAX;
        // Премортем #2: save-old/restore-old (не unset) — утечка env в соседние
        // фазы тика. Guard-пара apply/release.
        $guard = $this->applySearchEnv($beam);
        try {
            $engine = new DiscoveryEngine();
            $cvMax = (float) (getenv('VVERIFY_CV_TRAIN_MAX') ?: (string) self::CV_TRAIN_MAX);
            [$cands] = $engine->discover($X, $y, $grammar->all(), $cvMax);
        } finally {
            $guard();
        }
        $best = null;
        $bestCv = 9.99;
        foreach ($cands as $c) {
            $cv = (float) ($c['cv'] ?? 9.99);
            if ($best === null || $cv < $bestCv) {
                $best = $c;
                $bestCv = $cv;
            }
        }
        if ($best === null || ! is_string($best['atom'] ?? null)) {
            return null;
        }

        return [(string) $best['atom'], $bestCv];
    }

    private function shapeMatches(string $expectedShape, string $formula): bool
    {
        return LawShape::of($formula) === $expectedShape;
    }

    private function rowsOf(array $X, array $y): array
    {
        $rows = [];
        foreach ($X as $i => $features) {
            $rows[] = array_merge(array_values($features), [(float) ($y[$i] ?? 0.0)]);
        }

        return $rows;
    }

    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }

    /**
     * Лог V-исполнителя: канал вызывающего (Hive::log) или stderr.
     */
    private function logTask(string $event, array $vtask, string $detail): void
    {
        $kind = (string) ($vtask['kind'] ?? '?');
        $mark = str_contains($kind, 'inverted') ? 'VTASK:inverted' : 'VTASK';
        $line = "{$mark} {$event} task={$kind} law=" . ($vtask['law_formula'] ?? '?') . " {$detail}";
        if ($this->logger !== null) {
            ($this->logger)($line);

            return;
        }
        error_log($line);
    }
}
