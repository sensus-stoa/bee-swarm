#!/bin/bash
# RESUME for compound campaign: skips runs whose output JSON already exists.
# Run ONLY if the main loop died (check: grep COMPOUND_DONE campaign.log).
set -u
cd ~/.bee_swarm
OUT=~/.bee_swarm/Benchmarks/strategy_compound
mkdir -p "$OUT"
for s in 301 302 303 304 305 306 307 308 309 310; do
  for arm in O F R S; do
    J="$OUT/compound_${arm}_${s}.json"
    if [ -f "$J" ]; then continue; fi
    echo "=== RESUME $arm $s $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
    php scripts/exp045/strategy_driver.php compound "$arm" "$s" "$OUT/families.json" "$OUT" >> "$OUT/campaign.log" 2>&1
  done
done
echo "COMPOUND_DONE $(date -Iseconds)" >> "$OUT/campaign.log"
echo RESUME_FINISHED
