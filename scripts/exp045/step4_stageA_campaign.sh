#!/usr/bin/env bash
# EXP-045 Stage A: Evolution on the clean channel (PREREG_STAGE_A_EVOLUTION.md,
# AMENDMENT_STAGE_A: BASELINE_SHARE 0.2 — одна строка; сравнимость с dev_run2
# потеряна by design). 6 рук × 3 сида = 18 прогонов (baseline нужен для
# novel-cert). SEED_MIN_ACTIONS=3 SEED_BF=1 — шаг 1 лестницы §2.2.
# Запуск: держимый ssh (НЕ детач).
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_stageA_campaign
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteMutator.php src/Hive/RouteExecutor.php scripts/exp045/driver.php >> "$OUT/campaign.log"
php scripts/exp045/gen_dev_families.php "$OUT/dev_families.json" >> "$OUT/campaign.log" 2>&1
for arm in baseline random noinherit shuffled ordershuf evolved evolvednm; do
  for seed in 101 102 103; do
    echo "=== RUN $arm/$seed start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
    SEED_MIN_ACTIONS=3 SEED_BF=1 TRAIL_FIX=pool timeout 900 php scripts/exp045/driver.php "$arm" "$seed" "$OUT/dev_families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
    echo "=== RUN $arm/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  done
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
