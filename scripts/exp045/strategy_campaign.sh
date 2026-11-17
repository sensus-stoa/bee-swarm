#!/usr/bin/env bash
# STRATEGY-TRANSFER CAMPAIGN 1 (prereg rev.5, Q1) — 90 slots.
# Author: owner word 03.11 ((a) positive-reference = probe_loop 8/8 canon;
# B-rate 0/8 green; (b) = post-campaign). Detached run (setsid nohup).
set -u
cd ~/.bee_swarm
OUT=/tmp/strategy_campaign
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum scripts/exp045/strategy_driver.php scripts/exp045/gen_strategy_families.php \
       src/Hive/RouteMutator.php src/Hive/RouteGenome.php src/Hive/RouteExecutor.php \
       src/Hive/SensorState.php src/Hive/BudgetAccountant.php src/Hive/TransferStats.php \
       scripts/exp045/cert_utils.php ~/EvoFamily/ProductSpecification/stories/strategy-transfer/CODE_FREEZE_MANIFEST.txt >> "$OUT/campaign.log" 2>/dev/null
php scripts/exp045/gen_strategy_families.php "$OUT/families.json" >> "$OUT/campaign.log" 2>&1

for s in 101 102 103; do
  echo "=== EVOLVE A $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php evolve A "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
  echo "=== EVOLVE B $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php evolve B "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
  echo "=== REPLAYC C $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php replayC C "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
done
echo "=== FREEZE $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
php scripts/exp045/strategy_driver.php freeze X 101 "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
for s in 101 102 103; do
  echo "=== CROSSEVAL A $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php crosseval A "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
  echo "=== CROSSEVAL B $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php crosseval B "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
done
echo "=== COMPARE $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
php scripts/exp045/strategy_driver.php compare X 101 "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
echo "CAMPAIGN_DONE $(date -Iseconds)" >> "$OUT/campaign.log"
