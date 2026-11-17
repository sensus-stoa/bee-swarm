#!/usr/bin/env bash
# EXP-045 DOT4 DECOY (AMENDMENT_DOT4_DECOY): полная каузальность depth-claim.
# Руки: on+decoy x 101-103 (TRAIL_FIX=decoy, SEARCH_DEBUG=1 — телеметрия
# append'а) + off+fix x 101-103 (TRAIL_FIX=pool). taskSet dot4.
# Ре-юз: on+fix 22/24 (fixv2), on/off-fix 0/24 (базовый re-run).
# Запуск: держимый ssh (НЕ детач).
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_step4_decoy
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteExecutor.php scripts/exp045/probe_loop.php >> "$OUT/campaign.log"
for seed in 101 102 103; do
  echo "=== RUN on+decoy/$seed start $(date +%H:%M:%S) mem_used=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
  TRAIL_FIX=decoy SEARCH_DEBUG=1 timeout 900 php scripts/exp045/probe_loop.php on "$seed" x "$OUT" dot4 >> "$OUT/campaign.log" 2>&1
  echo "=== RUN on+decoy/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  mv "$OUT/results_probe_on_${seed}_dot4.json" "$OUT/results_probe_on_${seed}_dot4_decoy.json"
done
for seed in 101 102 103; do
  echo "=== RUN off+fix/$seed start $(date +%H:%M:%S) mem_used=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
  TRAIL_FIX=pool timeout 900 php scripts/exp045/probe_loop.php off "$seed" x "$OUT" dot4 >> "$OUT/campaign.log" 2>&1
  echo "=== RUN off+fix/$seed exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
  mv "$OUT/results_probe_off_${seed}_dot4.json" "$OUT/results_probe_off_${seed}_dot4_poolfix.json"
done
# Guard от no-op (урок сида-102 v1): decoy append обязан быть >0
APPENDED=$(grep -o 'fixAppended_n=[0-9]*' "$OUT/campaign.log" | awk -F= '$2>0' | wc -l)
LINES=$(grep -c 'fixAppended_n=' "$OUT/campaign.log" || true)
echo "DECOY_GUARD: merge-lines=$LINES with_append>0=$APPENDED" >> "$OUT/campaign.log"
echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
