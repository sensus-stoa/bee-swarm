#!/bin/bash
# EXP-065 B1.1: ablation sequential. Тёмные сиды 502-506 (конверт-проб: тёмные
# в ОБЕИХ конфигурациях). Руки: A0 chain-off, A1 chain-W+R, L lottery (8
# независимых попыток x 12s, чистая дорога каждая), T long-flight (96s x 1).
# P=1 (последовательно): wall-clock бюджет контеншн-чувствителен (урок B1).
# Нуль не гоняется (валидирован в B1: 0/10). Контроль детерминизма: 502/A1
# повторяется в конце (эталон = первый прогон).
set -u
cd "$(dirname "$0")/.."
LOG=Benchmarks/exp065_b11_map.log
FINAL=Benchmarks/exp065_b11_runs.jsonl
rm -f Benchmarks/exp065_b11_r[0-9]*.jsonl "$FINAL"

echo "=== EXP065 B1.1 SEQUENTIAL START $(date) P=1 ===" >> "$LOG"

run() { # SEED ARM [ATTEMPT]
  local SEED=$1 ARM=$2 ATTEMPT=${3:-0}
  local SHARD="Benchmarks/exp065_b11_r${SEED}_${ARM}_t${ATTEMPT}.jsonl"
  echo "[-- arm $ARM seed $SEED t$ATTEMPT start $(date +%H:%M:%S) --]" >> "$LOG"
  php scripts/exp065_b1_chain.php run "$SEED" "$ARM" "$SHARD" "$ATTEMPT" >> "$LOG" 2>&1
  echo "[-- arm $ARM seed $SEED t$ATTEMPT done rc=$? $(date +%H:%M:%S) --]" >> "$LOG"
}

for SEED in 502 503 504 505 506; do
  run "$SEED" A0
  run "$SEED" A1
done
for SEED in 502 503 504 505 506; do
  for T in 0 1 2 3 4 5 6 7; do
    run "$SEED" L "$T"
  done
done
for SEED in 502 503 504 505 506; do
  run "$SEED" T
done
# контроль детерминизма: повтор 502/A1 (эталон — первый прогон этой матрицы)
run 502 A1 0
mv Benchmarks/exp065_b11_r502_A1_t0.jsonl Benchmarks/exp065_b11_r502_A1_t0_repeat.jsonl 2>/dev/null || true

: > "$FINAL"
for F in Benchmarks/exp065_b11_r[0-9]*.jsonl; do [ -f "$F" ] && cat "$F" >> "$FINAL"; done
rm -f Benchmarks/exp065_b11_r[0-9]*.jsonl
echo "=== EXP065 B1.1 DONE $(date) total=$(wc -l < "$FINAL") ===" >> "$LOG"
