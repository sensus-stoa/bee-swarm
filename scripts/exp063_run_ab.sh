#!/bin/bash
# EXP-063 параллельный оркестратор: 2x2 (READ: ctrl|div x ADM: eval|cvargmin) x 15 сидов.
# Каждый прогон = изолированный php-процесс (SWARM_DB_PATH=:memory:) — «отдельный улей»,
# общего состояния между прогонами нет, параллелить безопасно. 10 воркеров на 12 ядрах.
# Каждый JOB пишет свой файл (по одному на сид x конфиг), в конце — детерминированное
# слияние в exp063_ab_runs.jsonl (параллельный аппенд в общий файл не атомарен для строк >100KB).
set -u
cd "$(dirname "$0")/.."
LOG=Benchmarks/exp063_ab_map.log
FINAL=Benchmarks/exp063_ab_runs.jsonl
PAR=${PAR:-10}
rm -f Benchmarks/exp063_ab_runs_r*.jsonl "$FINAL"

echo "=== EXP063 AB PARALLEL START $(date) workers=$PAR ===" >> "$LOG"

run_one() {
  SEED=$1; READ=$2; ADM=$3
  SHARD="Benchmarks/exp063_ab_runs_r${SEED}_${READ}_${ADM}.jsonl"
  echo "[-- $READ/$ADM seed $SEED start $(date +%H:%M:%S) pid $$ --]" >> "$LOG"
  php scripts/exp063_diversity_ab.php run "$SEED" "$READ" "$ADM" "$SHARD" >> "$LOG" 2>&1
  echo "[-- $READ/$ADM seed $SEED done rc=$? $(date +%H:%M:%S) --]" >> "$LOG"
}
export -f run_one
export LOG

{
for READ in ctrl div; do
  for ADM in eval cvargmin; do
    for SEED in 401 402 403 404 405 406 407 408 409 410 411 412 413 414 415; do
      echo "$SEED $READ $ADM"
    done
  done
done
} | xargs -L1 -P"$PAR" bash -c 'run_one "$@"' _

# Детерминированное слияние: конфиг, затем сид
: > "$FINAL"
for READ in ctrl div; do
  for ADM in eval cvargmin; do
    for SEED in 401 402 403 404 405 406 407 408 409 410 411 412 413 414 415; do
      cat "Benchmarks/exp063_ab_runs_r${SEED}_${READ}_${ADM}.jsonl" >> "$FINAL" 2>/dev/null
    done
  done
done
rm -f Benchmarks/exp063_ab_runs_r*.jsonl
echo "=== EXP063 AB DONE $(date) total=$(wc -l < "$FINAL") ===" >> "$LOG"
