#!/usr/bin/env bash
# EXP-045 E1-lite: Adaptive K калибровка + dilution/junk/material диагностика.
# Матрица: SCALE ∈ {4,8,16} × руки {evolved, evolvednm} × сиды {101,102} = 12.
# Задачи: DOT4A + DN4 (famJson e1_fam). ADAPTIVE_K=1, CAP=20, bf=0.10,
# TASK_BUDGET=450. Метрики: novel(DOT4A), injected yield, m3>0 incidence,
# topk_useful/junk (material quality). TRAIL_FIX=pool, TRAIL_MARKS=1.
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_e1lite
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteExecutor.php src/Hive/RouteLedger.php scripts/exp045/driver.php >> "$OUT/campaign.log"
php scripts/exp045/build_hand_genomes.php /tmp/e1lite_hand.json >> "$OUT/campaign.log" 2>&1

for scale in 4 8 16; do
  for arm in evolved evolvednm; do
    for seed in 101 102; do
      echo "=== RUN SCALE=$scale $arm/$seed start $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
      SEED_MIN_ACTIONS=3 SEED_BF=0.10 ADAPTIVE_K=1 ROAD_READ_SCALE=$scale ROAD_READ_CAP=20 \
      EXP045_TASK_BUDGET=450 TRAIL_MARKS=1 TRAIL_FIX=pool \
      timeout 900 php scripts/exp045/driver.php "$arm" "$seed" /tmp/exp045_stageA/e1_fam.json "$OUT" >> "$OUT/campaign.log" 2>&1
      echo "=== RUN SCALE=$scale $arm/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
      mv "$OUT/results_${arm}_${seed}.json" "$OUT/results_${arm}_${seed}_s${scale}.json"
    done
  done
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
