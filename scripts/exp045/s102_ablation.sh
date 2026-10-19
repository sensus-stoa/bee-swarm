#!/usr/bin/env bash
# EXP-045 сид-102: 2 руки (full-v4 vs -slots) на коде 176dfa8 (petal-гейт и
# markInLaw отличаются от шага II — обе руки перегоняются для чистоты).
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_s102
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== RUN full/102 start $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
timeout 900 php scripts/exp045/probe_loop.php on 102 x "$OUT" dot >> "$OUT/campaign.log" 2>&1
rc=$?
mv "$OUT/results_probe_on_102_dot.json" "$OUT/results_full_102_dot.json" 2>/dev/null
echo "=== RUN full/102 exit=$rc $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
echo "=== RUN -slots/102 start $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
TRAIL_ABLATE=slots timeout 900 php scripts/exp045/probe_loop.php on 102 x "$OUT" dot >> "$OUT/campaign.log" 2>&1
rc=$?
mv "$OUT/results_probe_on_102_dot.json" "$OUT/results_slots_102_dot.json" 2>/dev/null
echo "=== RUN -slots/102 exit=$rc $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
echo "S102_CAMPAIGN_DONE" >> "$OUT/campaign.log"
