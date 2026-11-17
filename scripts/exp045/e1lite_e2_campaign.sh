#!/usr/bin/env bash
# EXP-045 E1-lite E2-кампания (пост-grow, 31.10): evolved vs evolvednm
# на ЖЁСТКОМ сете e2_fam (6 dot4-ниш + 2 null), старый fitness
# (чистая (c)-проверка — см. PREREG_E1LITE_ROADYIELD.md поправку).
# GUARD: fam обязан содержать null-контроли (fuse-дисциплина) → abort.
# Прогон считается после two-pass GREEN.
set -u
cd ~/.bee_swarm

export SEED_MIN_ACTIONS=3 SEED_BF=1 TRAIL_MARKS=1 TRAIL_FIX=pool EXP045_TASK_BUDGET=450 SWARM_DB_PATH=:memory:
# WU SELECTION-CEIL (31.10): развязка selection-входа и cert-порога.
# TEMPORARY, not biological (reviewer): относительный ceil — следующая строка.
export SELECTION_CEIL=0.15
FAM=/tmp/e2_fam.json
OUT=/tmp/e1lite_e2
mkdir -p "$OUT"
LOG="$OUT/campaign.log"
{
  echo "=== e1lite_e2 campaign $(date -Iseconds) ==="
  for f in src/Core/Search.php src/Hive/RouteMutator.php src/Hive/RouteExecutor.php src/Hive/RouteLedger.php scripts/exp045/driver.php scripts/exp045/cert_utils.php scripts/exp045/gen_e2_families.php; do
    echo "md5 $f $(md5sum "$f" | cut -d' ' -f1)"
  done
} >> "$LOG"

# GUARD: fam обязан содержать null-контроли (fuse-дисциплина).
NULLS=$(python3 -c "
import json,sys
d=json.load(open('$FAM'))
ns=[t['id'] for t in d['tasks'] if t.get('null_shuffle')]
print(len(ns))")
if [ "$NULLS" -lt 2 ]; then
  echo "GUARD-FAIL: null-контролей меньше 2 ($NULLS)" >> "$LOG"
  exit 3
fi
echo "GUARD ok: nulls=$NULLS" >> "$LOG"

RUNS=()
for ARM in evolved evolvednm; do
  for SEED in 101 102 103; do
    RUNS+=("$ARM $SEED")
  done
done

for RUN in "${RUNS[@]}"; do
  ARM=${RUN% *}; SEED=${RUN#* }
  TAG="${ARM}_${SEED}"
  echo "RUN $TAG" >> "$LOG"
  php scripts/exp045/driver.php "$ARM" "$SEED" "$FAM" "$OUT/$TAG" 2>&1 | tail -3 >> "$LOG"
  echo "exit=$? $TAG" >> "$LOG"
done
echo "CAMPAIGN DONE" >> "$LOG"
echo "e1lite_e2 campaign done"
