#!/bin/bash
# PROBE-0 confirmatory chain: calibrate-gate -> discovery x4 -> test 3 arms x 4 replicates
set -u
cd ~/.bee_swarm
LOG=/tmp/probe0_chain.log
echo "[probe0] start $(date)" > "$LOG"

timeout 900 php scripts/probe0/driver.php calibrate Benchmarks/probe0/dev.json /tmp/probe0_run >> "$LOG" 2>&1
CAL=$?
B=$(php -r 'echo (string) json_decode(file_get_contents("/tmp/probe0_run/calibration.json"),true)["budget"];' 2>/dev/null || echo ERR)
echo "[probe0] calibrate exit=$CAL budget=$B" >> "$LOG"
if [ "$CAL" -ne 0 ] || [ "$B" != "25" ]; then echo "[probe0] CALIBRATION_GATE_FAIL (budget=$B)" >> "$LOG"; exit 1; fi

for r in 1 2 3 4; do
  timeout 600 php scripts/probe0/driver.php discover "$r" Benchmarks/probe0/discovery.json /tmp/probe0_run >> "$LOG" 2>&1
  echo "[probe0] discover r$r exit=$?" >> "$LOG"
done

for r in 1 2 3 4; do
  for arm in A B C; do
    timeout 2400 php scripts/probe0/driver.php test "$arm" "$r" Benchmarks/probe0/h_sealed.json /tmp/probe0_run 25 >> "$LOG" 2>&1
    echo "[probe0] test $arm r$r exit=$?" >> "$LOG"
  done
done
echo "[probe0] DONE $(date)" >> "$LOG"
