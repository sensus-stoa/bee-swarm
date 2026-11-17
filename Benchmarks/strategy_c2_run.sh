#!/usr/bin/env bash
set -u
cd ~/.bee_swarm
OUT=~/.bee_swarm/Benchmarks/strategy_c2
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) OUT=persistent ===" >> "$OUT/campaign.log"
md5sum scripts/exp045/strategy_driver.php src/Hive/RouteGenome.php src/Hive/RouteExecutor.php >> "$OUT/campaign.log" 2>/dev/null
php scripts/exp045/gen_strategy_families.php "$OUT/families.json" >> "$OUT/campaign.log" 2>&1
bash scripts/run_two_pass.sh >> "$OUT/twopass.log" 2>&1 || { echo "TWO_PASS_FAIL" >> "$OUT/campaign.log"; exit 1; }
echo "=== TWO_PASS_GREEN $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
for s in 301 302 303 304 305 306 307 308 309 310; do
  echo "=== EVOLVE A $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php evolve A "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
  echo "=== EVOLVE I $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php evolve I "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
  echo "=== EVOLVE B $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  php scripts/exp045/strategy_driver.php evolve B "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
done
echo "=== COMPARE2 $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
php scripts/exp045/strategy_driver.php compare2 X 101 "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
echo "C2_DONE $(date -Iseconds)" >> "$OUT/campaign.log"
