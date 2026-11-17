#!/bin/bash
# EXP-063b: та же 2×2 (READ x ADM), но дорога сеется ИСТИННЫМИ rungs закона cdeep2
# ['(x0×x2²)','(x3×x4)','x5'] — предпосылка E62-forensics («(c3×c4) written at l1,
# never read»). Декой-вариант (E57/E58 semantics) уже прогнан в exp063_ab_runs.jsonl.
set -u
cd "$(dirname "$0")/.."
LOG=Benchmarks/exp063b_map.log
FINAL=Benchmarks/exp063b_runs.jsonl
PAR=${PAR:-10}
rm -f Benchmarks/exp063b_runs_r*.jsonl "$FINAL"

echo "=== EXP063B (true rungs) PARALLEL START $(date) workers=$PAR ===" >> "$LOG"

run_one() {
  SEED=$1; READ=$2; ADM=$3
  SHARD="Benchmarks/exp063b_runs_r${SEED}_${READ}_${ADM}.jsonl"
  echo "[-- $READ/$ADM seed $SEED start $(date +%H:%M:%S) pid $$ --]" >> "$LOG"
  php scripts/exp063_diversity_ab.php run "$SEED" "$READ" "$ADM" true "$SHARD" >> "$LOG" 2>&1
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

: > "$FINAL"
for READ in ctrl div; do
  for ADM in eval cvargmin; do
    for SEED in 401 402 403 404 405 406 407 408 409 410 411 412 413 414 415; do
      cat "Benchmarks/exp063b_runs_r${SEED}_${READ}_${ADM}.jsonl" >> "$FINAL" 2>/dev/null
    done
  done
done
rm -f Benchmarks/exp063b_runs_r*.jsonl
echo "=== EXP063B DONE $(date) total=$(wc -l < "$FINAL") ===" >> "$LOG"
