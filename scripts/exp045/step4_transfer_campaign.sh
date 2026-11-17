#!/usr/bin/env bash
# EXP-045 ШАГ IV: Genome Transfer (PREREG_STEP4_TRANSFER.md — freeze).
# Реципиенты: D4 (families: D4x8 + DN1x2 + DN2x2 nulls) x {off, fresh, shuffled, transfer}
# + DOT4 shuffled/fresh x 101-103. TRAIL_FIX=pool ВЕЗДЕ (директива prereg).
# Реюз: DOT4 transfer 22/24 (fixv2), DOT/DOT4 off-руки (базовый re-run, decoy).
# Запуск: держимый ssh (НЕ детач).
set -u
cd ~/.bee_swarm
OUT=/tmp/exp045_step4_transfer
mkdir -p "$OUT"
: > "$OUT/campaign.log"
echo "=== PROVENANCE $(date -Iseconds) host=$(hostname) ===" >> "$OUT/campaign.log"
md5sum src/Core/Search.php src/Hive/RouteExecutor.php scripts/exp045/probe_loop.php >> "$OUT/campaign.log"
php scripts/exp045/gen_dev_families.php "$OUT/dev_families.json" >> "$OUT/campaign.log" 2>&1

run() {
  local label="$1"; shift
  echo "=== RUN $label start $(date +%H:%M:%S) mem=$(free -m | awk '/Mem:/{print $3}')MB ===" >> "$OUT/campaign.log"
  timeout 900 "$@" >> "$OUT/campaign.log" 2>&1
  echo "=== RUN $label exit=$? $(date +%H:%M:%S) ===" >> "$OUT/campaign.log"
}

# --- D4 (families; transfer с SEARCH_DEBUG для guard pool-append) ---
run "D4/off"          env TRAIL_FIX=pool php scripts/exp045/probe_loop.php off 101 "$OUT/dev_families.json" "$OUT" families
mv "$OUT/results_probe_off_101.json" "$OUT/results_probe_D4_off.json"
run "D4/fresh"        env TRAIL_FIX=pool php scripts/exp045/probe_loop.php on 101 "$OUT/dev_families.json" "$OUT" families fresh
mv "$OUT/results_probe_on_101_fresh.json" "$OUT/results_probe_D4_fresh.json"
run "D4/shuffled"     env TRAIL_FIX=pool php scripts/exp045/probe_loop.php on 101 "$OUT/dev_families.json" "$OUT" families shuffled
mv "$OUT/results_probe_on_101_shuffled.json" "$OUT/results_probe_D4_shuffled.json"
run "D4/transfer"     env TRAIL_FIX=pool SEARCH_DEBUG=1 php scripts/exp045/probe_loop.php on 101 "$OUT/dev_families.json" "$OUT" families ladder
mv "$OUT/results_probe_on_101.json" "$OUT/results_probe_D4_transfer.json"

# --- DOT4 контролы (transfer = реюз fixv2) ---
for seed in 101 102 103; do
  run "DOT4/shuffled/$seed" env TRAIL_FIX=pool php scripts/exp045/probe_loop.php on "$seed" x "$OUT" dot4 shuffled
  run "DOT4/fresh/$seed"    env TRAIL_FIX=pool php scripts/exp045/probe_loop.php on "$seed" x "$OUT" dot4 fresh
done

echo "CAMPAIGN_DONE" >> "$OUT/campaign.log"
