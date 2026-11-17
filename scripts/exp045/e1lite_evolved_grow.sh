#!/usr/bin/env bash
# E1-LITE EVOLVED (post-grow): стигмергия под отбором на чистом производстве.
# Prereg: PREREG_E1LITE_EVOLVED_GROW.md (заморожен 31.10 до прогона).
# 2 arms {evolved, evolvednm} × 3 seeds {101,102,103} = 6 прогонов.
# GUARD: DN4-fuse; PASS: pooled cert(evolved) > cert(evolvednm), без сида
# где evolvednm > evolved. Без SCALE-ручек (мёртвая ручка, вердикт 31.10).
set -u
cd ~/.bee_swarm
OUT=/tmp/e1lite_evolved_grow
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteMutator.php src/Hive/RouteExecutor.php src/Hive/RouteLedger.php scripts/exp045/driver.php >> "$OUT/campaign.log"
E1_FAM=/tmp/exp045_stageA/e1_fam.json
[ -f "$E1_FAM" ] || { echo "MISSING $E1_FAM" >> "$OUT/campaign.log"; exit 3; }
for arm in evolved evolvednm; do
  for seed in 101 102 103; do
    echo "=== RUN $arm/$seed start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
    SEED_MIN_ACTIONS=3 SEED_BF=1 TRAIL_MARKS=1 TRAIL_FIX=pool EXP045_TASK_BUDGET=450 \
    timeout 900 php scripts/exp045/driver.php "$arm" "$seed" "$E1_FAM" "$OUT" >> "$OUT/campaign.log" 2>&1
    echo "=== RUN $arm/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  done
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
