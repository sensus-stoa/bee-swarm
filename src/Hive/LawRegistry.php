<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * Law registry с preservation-аудитом (протокол §2.5.4, диссипативный контур).
 *
 * Реестр законов по поколениям. Аудит на gen-15 (preserveCheckGen):
 *   закон существует в reservoir И CV подтверждается на свежих данных → жив;
 *   иначе — событие LOSS (закон потерян роем = диссипация).
 *
 * Роль в контуре: observation-only. LOSS — событие, потребитель —
 * atom-penalty §2.5.6 (Phase 5). Не блокирует discovery, не удаляет строки.
 *
 * Хранение: законы живут в laws (общий reservoir). Registry добавляет
 * поколение открытия (generation) как атрибут аудита — отдельная таблица
 * law_generations, чтобы не трогать DDL laws (backwards-compat).
 */
final class LawRegistry
{
    public function __construct(
        private readonly int $preserveCheckGen,
        private readonly float $eps,
    ) {
    }

    /** Зарегистрировать закон с поколением открытия. */
    public function register(string $formula, string $domain, int $generation): void
    {
        Database::get()->prepare(
            'INSERT OR IGNORE INTO law_generations (formula, domain, generation) VALUES (?, ?, ?)'
        )->execute([$formula, $domain, $generation]);
    }

    public function exists(string $formula, string $domain): bool
    {
        $stmt = Database::get()->prepare(
            'SELECT 1 FROM law_generations WHERE formula = ? AND domain = ?'
        );
        $stmt->execute([$formula, $domain]);

        return $stmt->fetchColumn() !== false;
    }

    /** Односторонний state-переход (премортем З2c/З5): LOSS/OBSOLETE фиксируется. */
    private function markAuditState(string $formula, string $domain, string $state): void
    {
        Database::get()->prepare(
            "UPDATE law_generations SET audit_state = ? WHERE formula = ? AND domain = ?"
        )->execute([$state, $formula, $domain]);
    }

    public function getEps(): float
    {
        return $this->eps;
    }

    public function generationOf(string $formula, string $domain): ?int
    {
        $stmt = Database::get()->prepare(
            'SELECT generation FROM law_generations WHERE formula = ? AND domain = ?'
        );
        $stmt->execute([$formula, $domain]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    /**
     * Preservation-аудит текущего поколения.
     *
     * @param list<string> $aliveFormulas формулы, живые в reservoir сейчас
     * @param callable(string,string):bool $revalidate (formula, domain) → CV подтверждается
     * @return list<array{event: string, formula: string, domain: string, generation: int, evidence: string}>
     */
    public function audit(int $currentGeneration, float $eps, array $aliveFormulas = [], ?callable $revalidate = null): array
    {
        if ($currentGeneration < $this->preserveCheckGen) {
            return []; // ещё рано
        }

        // Премортем З3: in_array O(N×M) → isset на flip-массиве
        $aliveSet = array_flip($aliveFormulas);
        // Премортем З2c/З5: state-переход односторонний — LOSS/OBSOLETE не переэмитится
        $losses = [];
        $stmt = Database::get()->query(
            "SELECT formula, domain, generation, audit_state FROM law_generations
             WHERE audit_state = 'pending'"
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $formula = (string) $row['formula'];
            $domain = (string) $row['domain'];
            $generation = (int) $row['generation'];

            $alive = isset($aliveSet[$formula]);
            $cvOk = $revalidate !== null && $revalidate($formula, $domain);

            if ($alive && $cvOk) {
                continue; // закон жив и подтверждается — остаётся pending
            }

            $evidence = $alive
                ? 'cv_revalidation_failed'
                : 'vanished_from_reservoir';

            $this->markAuditState($formula, $domain, 'loss');

            $losses[] = [
                'event' => 'LOSS',
                'formula' => $formula,
                'domain' => $domain,
                'generation' => $generation,
                'evidence' => $evidence,
            ];
        }

        return $losses;
    }

    /**
     * Obsolescence recheck (протокол §2.5.5, Phase 4): каждые $recheckEvery
     * поколений перепроверять CV закона на свежих данных. CV > eps → флаг
     * OBSOLETE (закон устарел: данные сменились, зависимость перестала быть
     * инвариантом). Наблюдатель: флаг, не удаление.
     *
     * @return list<array{event: string, formula: string, domain: string, cv: float}>
     */
    public function obsolescenceCheck(int $currentGeneration, int $recheckEvery, callable $freshCv): array
    {
        if ($currentGeneration < $recheckEvery) {
            return []; // ещё рано
        }

        $obsolete = [];
        $stmt = Database::get()->query(
            "SELECT formula, domain, generation FROM law_generations
             WHERE audit_state = 'pending'"
        );
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $formula = (string) $row['formula'];
            $domain = (string) $row['domain'];
            // устарел ли: закон открыт давно (>= recheckEvery поколений назад)
            if ($currentGeneration - (int) $row['generation'] < $recheckEvery) {
                continue;
            }
            $cv = (float) $freshCv($formula, $domain);
            if ($cv > $this->eps) {
                $this->markAuditState($formula, $domain, 'obsolete');
                $obsolete[] = [
                    'event' => 'OBSOLETE',
                    'formula' => $formula,
                    'domain' => $domain,
                    'cv' => $cv,
                ];
            }
        }

        return $obsolete;
    }
}
