#!/usr/bin/env bash
# E1-LITE ADMISSION CAMPAIGN (prereg: PREREG_E1LITE_ADMISSION.md, 30.10)
# 9 прогонов probe_loop dot 101 ladder: admission-грид + ROAD_READ_SCALE-грид.
set -u
OUT=/tmp/e1lite_admission
rm -rf "$OUT" && mkdir -p "$OUT"
run () { # name, env...
  local name=$1; shift
  echo "=== ARM $name: env: $*"
  ( export "$@"; php scripts/exp045/probe_loop.php on 101 "" "$OUT/$name" dot ladder 2>&1 | tail -1 )
}
run BASELINE
run C50 TRAIL_ADMIT_CORR_MIN=0.5
run C70 TRAIL_ADMIT_CORR_MIN=0.7
run S1 TRAIL_ADMIT_STRUCT=1
run C50S1 TRAIL_ADMIT_CORR_MIN=0.5 TRAIL_ADMIT_STRUCT=1
run C70S1 TRAIL_ADMIT_CORR_MIN=0.7 TRAIL_ADMIT_STRUCT=1
run SCALE8 ADAPTIVE_K=1 ROAD_READ_SCALE=8
run SCALE16 ADAPTIVE_K=1 ROAD_READ_SCALE=16
run SCALE32 ADAPTIVE_K=1 ROAD_READ_SCALE=32
echo "=== CAMPAIGN DONE ==="
for d in "$OUT"/*/; do
  f="$d/results_probe_on_101_dot.json"
  [ -f "$f" ] && echo "$(basename "$d"): $(stat -c%s "$f") bytes"
done
