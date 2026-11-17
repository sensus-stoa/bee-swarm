#!/usr/bin/env bash
# EXP-045 Stage 2 v2: контрфактический тест функциональности (репликация
# структуры DOT4-пробы: 8 проходов лестницы, дорога накапливается между
# проходами — квorum/distinct readers работают между pass'ами).
# 3 прогона: ladder8 road-ON / ladder8 road-OFF / f2x58 road-ON.
# TASK_BUDGET=450 (routes 360s = пробный объём). Один seed 101.
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_stage2
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteExecutor.php src/Hive/RouteLedger.php scripts/exp045/driver.php >> "$OUT/campaign.log"

echo "=== RUN ladder8-ON start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
EXP045_TASK_BUDGET=450 TRAIL_MARKS=1 TRAIL_FIX=pool HAND_GENOME_JSON=/tmp/hand_ladder8.json timeout 1800 php scripts/exp045/driver.php random 101 /tmp/exp045_stageA/e1_fam.json "$OUT" >> "$OUT/campaign.log" 2>&1
echo "=== RUN ladder8-ON exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
mv "$OUT/results_random_101.json" "$OUT/results_stage2_ladder8_ON.json"

echo "=== RUN ladder8-OFF start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
EXP045_TASK_BUDGET=450 ROAD_MARKS_OFF=1 timeout 1800 php scripts/exp045/driver.php random 101 /tmp/exp045_stageA/e1_fam.json "$OUT" >> "$OUT/campaign.log" 2>&1
echo "=== RUN ladder8-OFF exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
mv "$OUT/results_random_101.json" "$OUT/results_stage2_ladder8_OFF.json"

echo "=== RUN f2x58-ON start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
EXP045_TASK_BUDGET=450 TRAIL_MARKS=1 TRAIL_FIX=pool HAND_GENOME_JSON=/tmp/hand_f2x58.json timeout 1800 php scripts/exp045/driver.php random 101 /tmp/exp045_stageA/e1_fam.json "$OUT" >> "$OUT/campaign.log" 2>&1
echo "=== RUN f2x58-ON exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
mv "$OUT/results_random_101.json" "$OUT/results_stage2_f2x58_ON.json"

echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
