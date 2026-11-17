#!/bin/bash
# EXP-065 B1: 30 прогонов (10 сидов 501-510 x руки A|B|C), 8 воркеров.
# Пререг: Benchmarks/EXP065_B1_prereg.md (rev.2). Шард-глоб: r[0-9]* (урок exp064).
set -u
cd "$(dirname "$0")/.."
LOG=Benchmarks/exp065_b1_map.log
FINAL=Benchmarks/exp065_b1_runs.jsonl
PAR=${PAR:-8}
rm -f Benchmarks/exp065_b1_r[0-9]*.jsonl "$FINAL"

echo "=== EXP065 B1 START $(date) workers=$PAR ===" >> "$LOG"

run_one() {
  SEED=$1; ARM=$2
  SHARD="Benchmarks/exp065_b1_r${SEED}_${ARM}.jsonl"
  echo "[-- arm $ARM seed $SEED start $(date +%H:%M:%S) --]" >> "$LOG"
  php scripts/exp065_b1_chain.php run "$SEED" "$ARM" "$SHARD" >> "$LOG" 2>&1
  echo "[-- arm $ARM seed $SEED done rc=$? $(date +%H:%M:%S) --]" >> "$LOG"
}
export -f run_one
export LOG

{
for SEED in 501 502 503 504 505 506 507 508 509 510; do
  for ARM in A B C; do
    echo "$SEED $ARM"
  done
done
} | xargs -L1 -P"$PAR" bash -c 'run_one "$@"' _

: > "$FINAL"
for ARM in A B C; do
  for SEED in 501 502 503 504 505 506 507 508 509 510; do
    cat "Benchmarks/exp065_b1_r${SEED}_${ARM}.jsonl" >> "$FINAL" 2>/dev/null
  done
done
rm -f Benchmarks/exp065_b1_r[0-9]*.jsonl
echo "=== EXP065 B1 DONE $(date) total=$(wc -l < "$FINAL") ===" >> "$LOG"
