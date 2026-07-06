<?php

declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * ResourceGuard v2: измеряет ПРОЦЕССНЫЙ CPU через /proc/self/stat.
 * v1 использовал sys_getloadavg() — системную нагрузку, а не процессную.
 */
class ResourceGuard
{
    private float $cpuLimit;
    private float $memLimit;
    private int $throttleSleep;
    private array $history = [];

    // Для измерения процессного CPU
    private ?float $lastCpuTime = null;
    private ?float $lastWallTime = null;
    private int $coreCount;

    public function __construct(float $cpuLimit = 0.5, float $memLimit = 0.5)
    {
        $this->cpuLimit = $cpuLimit;
        $this->memLimit = $memLimit;
        $this->throttleSleep = 200_000; // 200ms минимум
        $this->coreCount = max(1, (int)(@shell_exec('nproc 2>/dev/null') ?: 1));
    }

    /**
     * Измеряет процессный CPU через /proc/self/stat.
     * Возвращает долю CPU (0.0–coreCount).
     */
    private function getProcessCpu(): float
    {
        $stat = @file_get_contents('/proc/self/stat');
        if (!$stat) {
            return 0.0; // fallback
        }

        // /proc/self/stat: pid comm state ppid ... utime stime ...
        // utime и stime — поля 14 и 15 (0-indexed: 13 и 14)
        $parts = explode(' ', $stat);
        if (count($parts) < 15) {
            return 0.0;
        }

        // Обходим проблему с comm (может содержать пробелы в скобках)
        // Формат: pid (comm) state ...
        $closeParen = strrpos($stat, ')');
        if ($closeParen === false) {
            return 0.0;
        }

        $afterComm = substr($stat, $closeParen + 2); // пропускаем ") "
        $fields = explode(' ', $afterComm);

        // utime = fields[11], stime = fields[12] (после state, ppid, pgrp, session, tty_nr, tpgid, flags, minflt, cminflt, majflt, cmajflt)
        // state=0, ppid=1, pgrp=2, session=3, tty_nr=4, tpgid=5, flags=6, minflt=7, cminflt=8, majflt=9, cmajflt=10, utime=11, stime=12
        $utime = (float)($fields[11] ?? 0);
        $stime = (float)($fields[12] ?? 0);

        $totalCpuTime = ($utime + $stime) / 100.0; // jiffies → seconds (обычно 100Hz)
        $wallTime = microtime(true);

        if ($this->lastCpuTime === null) {
            $this->lastCpuTime = $totalCpuTime;
            $this->lastWallTime = $wallTime;
            return 0.0;
        }

        $cpuDelta = $totalCpuTime - $this->lastCpuTime;
        $wallDelta = $wallTime - $this->lastWallTime;

        $this->lastCpuTime = $totalCpuTime;
        $this->lastWallTime = $wallTime;

        if ($wallDelta <= 0) {
            return 0.0;
        }

        // Доля CPU: cpuDelta / wallDelta (0..coreCount на многопоточных)
        return $cpuDelta / $wallDelta;
    }

    private function getProcessMemory(): float
    {
        $usage = memory_get_usage(true);
        $total = $this->getTotalMemory();
        return $total > 0 ? $usage / $total : 0.0;
    }

    public function guard(): string
    {
        $status = 'ok';

        $cpuUsage = $this->getProcessCpu();
        $memUsage = $this->getProcessMemory();

        // CPU: нормализуем на количество ядер
        $cpuFraction = $cpuUsage / $this->coreCount;

        if ($cpuFraction > $this->cpuLimit) {
            $this->throttleSleep = min(5_000_000, (int)($this->throttleSleep * 1.5));
            $status = "throttle_cpu:" . round($cpuFraction, 2);
        } elseif ($memUsage > $this->memLimit) {
            $this->throttleSleep = min(5_000_000, (int)($this->throttleSleep * 1.5));
            $status = "throttle_mem:" . round($memUsage, 2);
        } elseif ($cpuFraction < $this->cpuLimit * 0.3 && $memUsage < $this->memLimit * 0.3) {
            $this->throttleSleep = max(200_000, (int)($this->throttleSleep * 0.8));
        }

        $this->history[] = [
            't' => time(),
            'cpu' => round($cpuFraction, 2),
            'mem' => round($memUsage, 2),
            's' => $status
        ];
        if (count($this->history) > 100) {
            array_shift($this->history);
        }

        return $status;
    }

    public function sleepUs(): int
    {
        return $this->throttleSleep;
    }

    public function stats(): array
    {
        if (!$this->history) {
            return ['cpu' => 0.0, 'mem' => 0.0, 'status' => 'ok'];
        }
        $last = end($this->history);
        return ['cpu' => $last['cpu'], 'mem' => $last['mem'], 'status' => $last['s']];
    }

    private function getTotalMemory(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
            return (int)$m[1] * 1024;
        }
        return 8 * 1024 * 1024 * 1024; // fallback 8GB
    }
}
