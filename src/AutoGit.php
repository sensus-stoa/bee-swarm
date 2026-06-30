<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * Авто-коммит изменений роя в git.
 * Каждое открытие, факт, изобретение → коммит.
 */
class AutoGit
{
    private static string $repoPath = '/swarm';
    private static int $lastCommit = 0;
    private static int $minInterval = 30; // секунд между коммитами
    
    public static function commit(string $message): void
    {
        $now = time();
        if ($now - self::$lastCommit < self::$minInterval) return;
        self::$lastCommit = $now;
        
        $msg = escapeshellarg("🐝 auto: $message");
        $cmd = "cd " . self::$repoPath . " && git add data/swarm.db 2>/dev/null; git diff --cached --quiet || git commit -m $msg 2>&1";
        @exec($cmd, $output, $code);
    }
    
    /** Закон найден */
    public static function lawDiscovered(string $name, string $formula, float $cv): void
    {
        self::commit("открыт закон: $name = $formula (CV=$cv)");
    }
    
    /** Факт выучен */
    public static function factLearned(string $subject, string $predicate, string $object): void
    {
        self::commit("факт: $subject $predicate $object");
    }
    
    /** Операция изобретена */
    public static function operationInvented(string $op): void
    {
        self::commit("NESTED: +$op");
    }
    
    /** Событие прожито */
    public static function experienceGained(string $event, array $effects): void
    {
        $summary = [];
        foreach ($effects as $k => $v) if ($v != 0) $summary[] = "$k$v";
        if (empty($summary)) return;
        self::commit("событие: $event (" . implode(', ', $summary) . ")");
    }
}
