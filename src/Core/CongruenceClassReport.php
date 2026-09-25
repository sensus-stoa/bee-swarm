<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * V0.15 WU-5: аудит-секция «Equivalence class».
 *
 * AUDIT_REPORT_TEMPLATE в репо нет (проверено grep 25.09) — по хендоффу
 * секция = рендер строк laws с class_id для experiments-log/CLI-отчёта,
 * не новый pipeline. Рядок: members, исторический домен [min..max] по
 * фичам, split point.
 */
final class CongruenceClassReport
{
    /**
     * @param list<array<string, mixed>> $rows строки laws (formula, domain,
     *                                         class_id, class_domain_json)
     */
    public static function render(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $classId = $row['class_id'] ?? null;
            if ($classId === null || $classId === '') {
                continue;
            }
            $decoded = json_decode((string) ($row['class_domain_json'] ?? ''), true);
            $members = is_array($decoded['members'] ?? null) ? $decoded['members'] : [];
            $domain = is_array($decoded['domain'] ?? null) ? $decoded['domain'] : [];
            $splitAt = $decoded['split_at'] ?? null;
            $out .= 'Equivalence class: ' . count($members) . ' formulas, domain '
                . self::domainString($domain) . ', split point: '
                . (is_array($splitAt) ? '#' . (int) ($splitAt['index'] ?? -1) : 'none')
                . "\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $domain
     */
    private static function domainString(array $domain): string
    {
        if ($domain === []) {
            return '[unknown]';
        }
        $parts = [];
        foreach ($domain as $bounds) {
            if (is_array($bounds) && count($bounds) === 2) {
                $parts[] = sprintf('[%s..%s]', self::num($bounds[0]), self::num($bounds[1]));
            }
        }

        return $parts === [] ? '[unknown]' : implode(' x ', $parts);
    }

    private static function num(float|int|string $v): string
    {
        return is_string($v) ? $v : (string) (float) $v;
    }
}
