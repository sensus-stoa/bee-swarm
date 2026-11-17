#!/usr/bin/env bash
# EXP-045 AMENDMENT_DOT4_FIXV2: DOT4 кампания с TRAIL_FIX=pool (on × 101-103).
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_dot4fixv2
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) TRAIL_FIX=pool ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php scripts/exp045/probe_loop.php >> "$OUT/campaign.log"
for seed in 101 102 103; do
  echo "=== RUN on+fix/$seed start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
  TRAIL_FIX=pool timeout 900 php scripts/exp045/probe_loop.php on "$seed" x "$OUT" dot4 >> "$OUT/campaign.log" 2>&1
  rc=$?
  if [ -f "$OUT/results_probe_on_${seed}_dot4.json" ]; then
    mv "$OUT/results_probe_on_${seed}_dot4.json" "$OUT/results_fixv2_on_${seed}_dot4.json"
  fi
  echo "=== RUN on+fix/$seed exit=$rc $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
