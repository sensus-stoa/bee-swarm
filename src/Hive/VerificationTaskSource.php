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
     * Возвращает in-memory спецификации задач; факт записи — таблица
     * verification_tasks (persist глушит сбой: наблюдатель-контракт, сбой
     * не роняет discovery).
     */
    public function spawnForLaw(string $lawFormula, string $domain, string $fingerprint): array
    {
        // Канон-ключ: cross-table инвариант (fake-LOSS урок) — все писатели
        // V-задач и laws обязаны использовать одну нормализацию формулы.
        $canon = ExpressionNormalizer::normalize($lawFormula);
        $shape = LawShape::of($canon);
        $lawId = $this->resolveLawId($canon, $domain);
        // Премортем #3 (10.09): в живом пути хук стоит после record → miss =
        // канон-дрейф между писателями. Молчание = таски-сироты без сигнала.
        if ($lawId === 0) {
            error_log("VTS resolve miss: law_id=0 formula={$canon} domain={$domain}");
        }

        $stmt = Database::get()->prepare(
            'INSERT OR IGNORE INTO verification_tasks
             (law_id, law_formula, law_shape, kind, resample_seed, target_sign, fingerprint, domain)
             VALUES (?,?,?,?,?,?,?,?)'
        );

        $tasks = [];
        foreach ($this->lawSpecs() as $s) {
            $task = [
                'law_id' => $lawId,
                'law_formula' => $canon,
                'law_shape' => $shape,
                'kind' => $s['kind'],
                'resample_seed' => $s['resample_seed'],
                'target_sign' => $s['target_sign'],
                'fingerprint' => $fingerprint,
                'domain' => $domain,
            ];
            $tasks[] = $task;
            $this->persist($stmt, $task);
        }

        return $tasks;
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
            $stmt->execute([
                $task['law_id'], $task['law_formula'], $task['law_shape'],
                $task['kind'], $task['resample_seed'], $task['target_sign'],
                $task['fingerprint'], $task['domain'],
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
    public function spawnInvertedResearch(string $formulaA, string $formulaB, string $domain): void
    {
        $canonA = ExpressionNormalizer::normalize($formulaA);
        $canonB = ExpressionNormalizer::normalize($formulaB);

        // formula_b в UNIQUE: две пары противоречий с одним canonA, но разными
        // альтернативами — разные задачи (находка ревью #2, 10.09).
        $stmt = Database::get()->prepare(
            'INSERT OR IGNORE INTO verification_tasks
             (law_id, law_formula, law_shape, kind, resample_seed, target_sign, fingerprint, formula_a, formula_b, domain)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        try {
            $stmt->execute([
                0,
                $canonA,
                LawShape::of($canonA),
                self::KIND_INVERTED_RESEARCH,
                0,
                self::SIGN_INVERTED,
                '',
                $canonA,
                $canonB,
                $domain,
            ]);
        } catch (\PDOException $e) {
            error_log('VTS research spawn failed: ' . $e->getMessage());
        }
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
