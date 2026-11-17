#!/bin/bash
# EXP-064 лёгкий пилот (owner 17.11): W+R diversity on, partial-дорога,
# новые сиды 501-505, ADM-ось = eval|cvargmin (реабилитация cv_argmin на живой дороге).
# Вопрос: собирается ли полный композит (cert с cert_form = истинный закон)?
# Контроль: W=off/R=off/eval на сидах 501, 503 (атрибуция эффекта).
set -u
cd "$(dirname "$0")/.."
LOG=Benchmarks/exp064_pilot_map.log
FINAL=Benchmarks/exp064_pilot_runs.jsonl
PAR=${PAR:-10}
rm -f Benchmarks/exp064_pilot_r[0-9]*.jsonl "$FINAL"

echo "=== EXP064 PILOT START $(date) workers=$PAR ===" >> "$LOG"

run_one() {
  SEED=$1; READ=$2; ADM=$3; WD=$4
  SHARD="Benchmarks/exp064_pilot_r${SEED}_${READ}_${ADM}_${WD}.jsonl"
  echo "[-- wd=$WD $READ/$ADM seed $SEED start $(date +%H:%M:%S) --]" >> "$LOG"
  if [ "$WD" = "on" ]; then
    TRAIL_WRITE_DIVERSITY=1 php scripts/exp063_diversity_ab.php run "$SEED" "$READ" "$ADM" partial "$SHARD" >> "$LOG" 2>&1
  else
    php scripts/exp063_diversity_ab.php run "$SEED" "$READ" "$ADM" partial "$SHARD" >> "$LOG" 2>&1
  fi
  echo "[-- wd=$WD $READ/$ADM seed $SEED done rc=$? $(date +%H:%M:%S) --]" >> "$LOG"
}
export -f run_one
export LOG

{
  # Treatment: W+R on, обе руки адмиссии, 5 новых сидов
  for ADM in eval cvargmin; do
    for SEED in 501 502 503 504 505; do
      echo "$SEED div $ADM on"
    done
  done
  # Контроль (атрибуция): full-off, eval, сиды 501/503
  for SEED in 501 503; do
    echo "$SEED ctrl eval off"
  done
} | xargs -L1 -P"$PAR" bash -c 'run_one "$@"' _

: > "$FINAL"
for SEED in 501 502 503 504 505; do
  for F in "Benchmarks/exp064_pilot_r${SEED}_div_eval_on.jsonl" \
           "Benchmarks/exp064_pilot_r${SEED}_div_cvargmin_on.jsonl"; do
    [ -f "$F" ] && cat "$F" >> "$FINAL"
  done
done
for SEED in 501 503; do
  F="Benchmarks/exp064_pilot_r${SEED}_ctrl_eval_off.jsonl"
  [ -f "$F" ] && cat "$F" >> "$FINAL"
done
rm -f Benchmarks/exp064_pilot_r[0-9]*.jsonl
echo "=== EXP064 PILOT DONE $(date) total=$(wc -l < "$FINAL") ===" >> "$LOG"
