#!/usr/bin/env bash
# EXP-045 ШАГ IV (Column 5): DOT4 DEPTH PROBE re-run на чистом канале Road v4.
# Канон: PREREG_DOT4_DEPTH_PROBE.md (критерии ЗАМОРОЖЕНЫ, неизменны)
#        + HANDOFF_DOT4_RERUN.md (процедура).
# Директива хендоффа: TRAIL_FIX НЕ включать. taskSet dot4: DOT4x8 + DN4x2 null.
# Запуск: держимый ssh (НЕ детач): ssh laptop 'bash ~/.bee_swarm/scripts/exp045/step4_dot4_campaign.sh'
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_step4_dot4
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteExecutor.php scripts/exp045/probe_loop.php >> "$OUT/campaign.log"
for spec in off:101 off:102 off:103 on:101 on:102 on:103; do
  arm="${spec%%:*}"
  seed="${spec##*:}"
  echo "=== RUN $arm/$seed start $(date +%H:%M:%S) mem_used=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
  timeout 900 php scripts/exp045/probe_loop.php "$arm" "$seed" x "$OUT" dot4 >> "$OUT/campaign.log" 2>&1
  echo "=== RUN $arm/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
done
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
