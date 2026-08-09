#!/usr/bin/env python3
"""Watchdog-прогон тестов: каждый тестовый файл с лимитом 2 мин.
Медленные/зависшие (>120с) — убиваются, показываются, идём дальше.

Использование: python3 scripts/watchdog_suite.py [--limit 120]
Выход: /tmp/watchdog_suite.log + итог в stdout (медленные файлы).
"""
import glob
import os
import subprocess
import sys
import time

LIMIT = int(os.environ.get("WATCHDOG_LIMIT", "120"))
TEST_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "tests")
LOG = "/tmp/watchdog_suite.log"

files = sorted(glob.glob(os.path.join(TEST_DIR, "*Test.php")))
if not files:
    print("Тестов не найдено в", TEST_DIR)
    sys.exit(1)

slow = []
results = []
total_start = time.time()

with open(LOG, "w", encoding="utf-8") as log:
    for i, f in enumerate(files, 1):
        name = os.path.basename(f)
        start = time.time()
        try:
            r = subprocess.run(
                ["timeout", str(LIMIT), "vendor/bin/phpunit", "--no-progress",
                 "--configuration", "phpunit.xml", f],
                cwd=os.path.dirname(os.path.abspath(__file__)) + "/..",
                capture_output=True, text=True, timeout=LIMIT + 30,
            )
            elapsed = time.time() - start
            status = "OK" if r.returncode == 0 else f"FAIL({r.returncode})"
            if r.returncode == 124:
                status = "HANG>120s"
                slow.append((name, elapsed))
            elif elapsed > LIMIT:
                status = f"SLOW({elapsed:.0f}s)"
                slow.append((name, elapsed))
        except subprocess.TimeoutExpired:
            elapsed = time.time() - start
            status = "HANG>TIMEOUT"
            slow.append((name, elapsed))

        line = f"[{i}/{len(files)}] {name}: {status} ({elapsed:.1f}s)"
        print(line, flush=True)
        log.write(line + "\n")
        results.append((name, status, elapsed))

total = time.time() - total_start
summary = f"\n=== ИТОГ: {len(files)} файлов, {total:.0f}s total, {len(slow)} медленных/зависших ==="
print(summary)
log.write(summary + "\n")
if slow:
    for name, t in sorted(slow, key=lambda x: -x[1]):
        line = f"  SLOW/HANG: {name} ({t:.0f}s)"
        print(line)
        log.write(line + "\n")
else:
    print("  Все файлы в пределах лимита.")
