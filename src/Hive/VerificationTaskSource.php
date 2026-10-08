<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Infra\Database;

/**
 * V0.14 WU-1 (verification-economy): источник верификационных задач.
 *
 * Среда порождает V-задачи из сигналов роя:
 *  - новый закон (recordDiscovery) → 5×resample_1..5 + 1×inverted;
 *  - противоречие (runContradictionCheck) → 1×inverted_research.
 *
 * Задача несёт полный контракт исполнителя WU-2:
 * {law_id, law_formula, law_shape, kind, resample_seed, target_sign, fingerprint}.
 *
 * Наблюдатель: спавн не бросает — сбой записи логируется, discovery не блокируется.
 */
final class VerificationTaskSource
{
    /**
     * V-задач на один новый закон (протокол WU-1: 5 resample + 1 inverted).
     */
    public const RESAMPLE_COUNT = 5;

    /**
     * Виды V-задач (единый словарь — дрейф-риск строк в SQL пойман ревью).
     */
    public const KIND_INVERTED = 'inverted';

    public const KIND_INVERTED_RESEARCH = 'inverted_research';

    /**
     * Знак цели: resample ищет тот же знак, inverted — противоположный.
     */
    public const SIGN_POSITIVE = 1;

    public const SIGN_INVERTED = -1;

    /**
     * Спавн 6 V-задач (5 resample + 1 inverted) на новый закон.
     *
     * @param array $sliceRows capped train-срез [[x..., y], ...] — данные задачи
     *     для асинхронного исполнителя (WU-2); пишется в data_json каждой задачи.
     * Возвращает in-memory спецификации задач; факт записи — таблица
     * verification_tasks (persist глушит сбой: наблюдатель-контракт, сбой
     * не роняет discovery).
     */
    public function spawnForLaw(string $lawFormula, string $domain, string $fingerprint, array $sliceRows = [], ?float $epsilon = null, ?array $colLabels = null): array
    {
        // Канон-ключ: cross-table инвариант (fake-LOSS урок) — все писатели
        // V-задач и laws обязаны использовать одну нормализацию формулы.
        $canon = ExpressionNormalizer::normalize($lawFormula);
        $lawId = $this->resolveLawId($canon, $domain);
        $this->logResolveMiss($lawId, $canon, $domain);

        $stmt = Database::get()->prepare(
            'INSERT OR IGNORE INTO verification_tasks
             (law_id, law_formula, law_formula_generic, law_shape, kind, resample_seed, target_sign, fingerprint, domain, data_json, epsilon)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );

        return $this->persistLawTasks($stmt, $this->lawTaskBase(
            $lawId,
            $canon,
            $colLabels,
            $fingerprint,
            $domain,
            $this->encodeSlice($sliceRows),
            $this->guardEpsilon($epsilon)
        ));
    }

    /**
     * VERIF-COLLABEL-PARITY (08.10): generic-канон = перевод имён колонок
     * в xN (конвенция Search::testCv). law_shape — маска GENERIC-формы
     * (паритет с ресемпл-срезом исполнителя); law_formula остаётся
     * доменным (join-ключ laws/escrow). Без labels — прежний контракт.
     *
     * @return array<string, mixed>
     */
    private function lawTaskBase(int $lawId, string $canon, ?array $colLabels, string $fingerprint, string $domain, ?string $dataJson, ?float $epsilon): array
    {
        $generic = LawShape::toGeneric($canon, $colLabels);

        return [
            'law_id' => $lawId,
            'law_formula' => $canon,
            'law_formula_generic' => $generic,
            'law_shape' => LawShape::of($generic),
            'fingerprint' => $fingerprint,
            'domain' => $domain,
            'data_json' => $dataJson,
            'epsilon' => $epsilon,
        ];
    }

    /**
     * Премортем #3 (10.09): в живом пути хук стоит после record → miss =
     * канон-дрейф между писателями. Молчание = таски-сироты без сигнала.
     */
    private function logResolveMiss(int $lawId, string $canon, string $domain): void
    {
        if ($lawId === 0) {
            error_log("VTS resolve miss: law_id=0 formula={$canon} domain={$domain}");
        }
    }

    private function encodeSlice(array $sliceRows): ?string
    {
        return $sliceRows === [] ? null : (string) json_encode($sliceRows);
    }

    /**
     * 6 V-задач из базового контракта + lawSpecs(); факт записи — таблица.
     */
    private function persistLawTasks(\PDOStatement $stmt, array $base): array
    {
        $tasks = [];
        foreach ($this->lawSpecs() as $s) {
            $task = array_merge($base, [
                'kind' => $s['kind'],
                'resample_seed' => $s['resample_seed'],
                'target_sign' => $s['target_sign'],
            ]);
            $tasks[] = $task;
            $this->persist($stmt, $task);
        }

        return $tasks;
    }

    /**
     * V0.16 WU-2 (verifier-eps-parity): inequality-guard спеки — порог
     * верификатора не может превышать калибровку открывателя. Malformed
     * epsilon (≤0, не-finite) клампится к ghost-пути (NULL → константа на
     * исполнителе), не автопроход (?? INF класс 16.09).
     */
    private function guardEpsilon(?float $epsilon): ?float
    {
        if ($epsilon !== null && (! is_finite($epsilon) || $epsilon <= 0.0)) {
            return null;
        }

        return $epsilon;
    }

    /**
     * V-задачи одного закона: 5 resample (sign=1) + 1 inverted (sign=-1).
     */
    private function lawSpecs(): array
    {
        $specs = [];
        for ($k = 1; $k <= self::RESAMPLE_COUNT; $k++) {
            $specs[] = [
                'kind' => "resample_{$k}",
                'resample_seed' => $k,
                'target_sign' => self::SIGN_POSITIVE,
            ];
        }
        $specs[] = [
            'kind' => self::KIND_INVERTED,
            'resample_seed' => 0,
            'target_sign' => self::SIGN_INVERTED,
        ];

        return $specs;
    }

    /**
     * Наблюдатель: сбой записи не роняет discovery (WU-1 контракт).
     */
    private function persist(\PDOStatement $stmt, array $task): void
    {
        try {
            // ПОРЯДОК = порядок плейсхолдеров INSERT (питфолл 05.09: перепутанный
            // порядок молча пишет данные в чужие колонки).
            $stmt->execute([
                $task['law_id'], $task['law_formula'], $task['law_formula_generic'],
                $task['law_shape'],
                $task['kind'], $task['resample_seed'], $task['target_sign'],
                $task['fingerprint'], $task['domain'], $task['data_json'],
                $task['epsilon'],
            ]);
        } catch (\PDOException $e) {
            // Премортем #4: контекст в строке — grep-уемость при write-contention.
            error_log("VTS spawn failed law={$task['law_formula']} kind={$task['kind']} domain={$task['domain']}: " . $e->getMessage());
        }
    }

    /**
     * CONTRADICTION-сигнал → research-задача: обе гипотезы противоречия
     * уезжают в одну задачу для исполнителя WU-2.
     */
    public function spawnInvertedResearch(string $formulaA, string $formulaB, string $domain, ?array $colLabels = null): void
    {
        $canonA = ExpressionNormalizer::normalize($formulaA);
        $canonB = ExpressionNormalizer::normalize($formulaB);
        // VERIF-COLLABEL-PARITY (08.10): второй писатель law_shape — та же
        // конвенция generic-маски, что и spawnForLaw (пацанс-аудит WU-5).
        $genericA = LawShape::toGeneric($canonA, $colLabels);

        try {
            $this->persistResearchTask($canonA, $canonB, $genericA, $domain);
        } catch (\PDOException $e) {
            error_log('VTS research spawn failed: ' . $e->getMessage());
        }
    }

    /**
     * formula_b в UNIQUE: две пары противоречий с одним canonA, но разными
     * альтернативами — разные задачи (находка ревью #2, 10.09).
     */
    private function persistResearchTask(string $canonA, string $canonB, string $genericA, string $domain): void
    {
        $stmt = Database::get()->prepare(
            'INSERT OR IGNORE INTO verification_tasks
             (law_id, law_formula, law_formula_generic, law_shape, kind, resample_seed, target_sign, fingerprint, formula_a, formula_b, domain)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            0,
            $canonA,
            $genericA,
            LawShape::of($genericA),
            self::KIND_INVERTED_RESEARCH,
            0,
            self::SIGN_INVERTED,
            '',
            $canonA,
            $canonB,
            $domain,
        ]);
    }

    /**
     * law_id резолвится из laws по канон-формуле; 0 = закон ещё не записан.
     */
    private function resolveLawId(string $canonFormula, string $domain): int
    {
        $stmt = Database::get()->prepare(
            'SELECT id FROM laws WHERE formula = ? AND domain = ? LIMIT 1'
        );
        $stmt->execute([$canonFormula, $domain]);
        $id = $stmt->fetchColumn();

        return $id === false ? 0 : (int) $id;
    }
}
