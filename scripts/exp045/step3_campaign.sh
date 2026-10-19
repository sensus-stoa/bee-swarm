#!/usr/bin/env bash
# EXP-045 ШАГ III ablation — 4 руки × 2 сида (prereg PREREG_STEP3_ABLATION.md).
# ВАЖНО: probe_loop называет JSON без слоя (arm/seed) — после каждого прогона
# файл переименовывается в results_<layer>_<seed>_dot.json.
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_step3
mkdir -p "$OUT"
: > "$OUT/campaign.log"
for layer in write tau slots nmd; do
  for seed in 101 103; do
    echo "=== RUN -$layer/$seed start $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
    TRAIL_ABLATE="$layer" timeout 900 php scripts/exp045/probe_loop.php on "$seed" x "$OUT" dot >> "$OUT/campaign.log" 2>&1
    rc=$?
    if [ -f "$OUT/results_probe_on_${seed}_dot.json" ]; then
      mv "$OUT/results_probe_on_${seed}_dot.json" "$OUT/results_${layer}_${seed}_dot.json"
    fi
    echo "=== RUN -$layer/$seed exit=$rc $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  done
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
