#!/usr/bin/env bash
# EXP-045 ШАГ II A/B DOT — оставшиеся 5 прогонов (зонд on/101 выполнен отдельно).
# Канон: PREREG_DOT_VALUE_PROBE.md критерии неизменны; канал 15s; taskSet dot.
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_step2
mkdir -p "$OUT"
: > "$OUT/campaign.log"
for spec in off:101 off:102 off:103 on:102 on:103; do
  arm="${spec%%:*}"
  seed="${spec##*:}"
  echo "=== RUN $arm/$seed start $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  timeout 900 php scripts/exp045/probe_loop.php "$arm" "$seed" x "$OUT" dot >> "$OUT/campaign.log" 2>&1
  echo "=== RUN $arm/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
