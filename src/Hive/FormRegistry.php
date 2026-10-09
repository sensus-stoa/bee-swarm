<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * S2.2-TER (§3.2.2): form_registry - the "law about laws" table.
 *
 * One mathematical FORM recurring across unrelated domains with different
 * parameters (power law: gravity, metabolism, linguistics). Every NEW law
 * (recordDiscovery, inserted=true) adds (form, domain, params) to this
 * registry; a form seen in >=3 DISTINCT domains with parameters >=10x in
 * at least one pair and cv <= 0.15 everywhere becomes 'invariant'.
 *
 * Story criterion is stricter than protocol §3.2.2 (10x parameter spread
 * + conjunctive cv gate) - preregistration 10.10, progress.md.
 *
 * Meta-laws live ONLY here (own status), never in laws - the two tables
 * answer different questions (which law in which domain / which form is
 * invariant across domains).
 */
final class FormRegistry
{
    /**
     * Distinct domains required for invariant status (§3.2.2).
     */
    private const MIN_DOMAINS = 3;

    /**
     * Parameter spread required in at least one pair (story criterion).
     */
    private const PARAM_RATIO_MIN = 10.0;

    /**
     * cv gate per domain (conjunction; protocol CV_train <= 0.15).
     */
    private const CV_MAX = 0.15;

    public static function register(
        string $formHash,
        string $domain,
        float $cv,
        array $params,
        string $lawName,
        string $formula
    ): void {
        $db = Database::get();

        $stmt = $db->prepare('SELECT id, domains, params, cvs, laws, status FROM form_registry WHERE form_hash = ?');
        $stmt->execute([$formHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            $db->prepare(
                'INSERT INTO form_registry
                 (form_hash, form_template, domains, params, cvs, laws, status, found_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))'
            )->execute([
                $formHash,
                $formHash,
                json_encode([$domain]),
                json_encode([$params]),
                json_encode([$cv]),
                json_encode([[
                    'law' => $lawName,
                    'formula' => $formula,
                    'domain' => $domain,
                ]]),
                self::verdict([$cv], [$params]) ? 'invariant' : 'observed',
            ]);
            return;
        }

        $domains = json_decode((string) $row['domains'], true) ?: [];
        $paramsAll = json_decode((string) $row['params'], true) ?: [];
        $cvs = json_decode((string) $row['cvs'], true) ?: [];
        $laws = json_decode((string) $row['laws'], true) ?: [];
        // Dedup by (hash, domain): same form re-found in the same domain
        // updates nothing (the domain count is the invariant signal).
        if (in_array($domain, $domains, true)) {
            return;
        }
        $domains[] = $domain;
        $paramsAll[] = $params;
        $cvs[] = $cv;
        $laws[] = [
            'law' => $lawName,
            'formula' => $formula,
            'domain' => $domain,
        ];
        $status = ((string) $row['status'] === 'invariant')
            ? 'invariant' // monotone: invariance is a discovered fact
            : (self::verdict($cvs, $paramsAll) ? 'invariant' : 'observed');
        $db->prepare(
            'UPDATE form_registry
             SET domains = ?, params = ?, cvs = ?, laws = ?, status = ?
             WHERE id = ?'
        )->execute([
            json_encode($domains),
            json_encode($paramsAll),
            json_encode($cvs),
            json_encode($laws),
            $status,
            (int) $row['id'],
        ]);
    }

    /**
     * Conjunctive invariant verdict: >=3 distinct domains, every cv <= 0.15,
     * parameter ratio >=10x in at least one pair.
     *
     * @param array<float> $cvs
     * @param array<array<string, float>> $paramsAll numeric param sets per domain
     */
    private static function verdict(array $cvs, array $paramsAll): bool
    {
        if (count($cvs) < self::MIN_DOMAINS) {
            return false;
        }
        foreach ($cvs as $cv) {
            if (! is_numeric($cv) || (float) $cv > self::CV_MAX) {
                return false;
            }
        }
        $minRatio = self::minPairRatio($paramsAll);
        return $minRatio !== null && $minRatio >= self::PARAM_RATIO_MIN;
    }

    /**
     * Maximum pairwise ratio over comparable scalar params, normalized to
     * >=1 (order-free: max/min). Pair = same semantic index across domains
     * (params arrays are positional); within a domain the largest numeric
     * param stands for the set (single-parameter forms are the target).
     *
     * @param array<array<string, float>> $paramsAll
     */
    private static function minPairRatio(array $paramsAll): ?float
    {
        $scalars = [];
        foreach ($paramsAll as $set) {
            $vals = [];
            foreach ((array) $set as $v) {
                if (is_int($v) || is_float($v)) {
                    $vals[] = abs((float) $v);
                }
            }
            if ($vals === []) {
                return null; // a domain without numeric params - not comparable
            }
            $scalars[] = max($vals);
        }
        $min = min($scalars);
        if ($min <= 0.0) {
            return null; // zero-param domain: ratio undefined
        }
        return max($scalars) / $min;
    }
}
