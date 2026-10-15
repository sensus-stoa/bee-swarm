#!/bin/bash
# PROBE-1 parallel chain: 12 ядер, параллельные lanes (arm × replicate).
# Каждая lane = отдельный php-процесс со своей БД и своим сидом — детерминизм
# не зависит от параллельности; 12 процессов ≤ 12 ядер → wall-бюджеты
# сопоставимы (выделенное ядро на процесс).
set -u
cd ~/.bee_swarm
LOG=/tmp/probe1_chain.log
echo "[probe1] start $(date)" > "$LOG"

# G1 (быстрый, последовательный — 4 × ~3s)
for r in 1 2 3 4; do
  timeout 300 php scripts/probe1/driver.php g1 "$r" /tmp/probe1_run >> "$LOG" 2>&1
  echo "[probe1] g1 r$r exit=$?" >> "$LOG"
done

# G2: 12 параллельных lanes (A/B/C × r1-r4)
PIDS=()
for arm in A B C; do
  for r in 1 2 3 4; do
    timeout 1400 php scripts/probe1/driver.php g2 "$arm" "$r" /tmp/probe1_run > "/tmp/probe1_run/g2_${arm}_${r}.lane" 2>&1 &
    PIDS+=($!)
  done
done
echo "[probe1] G2 lanes launched: ${#PIDS[@]}" >> "$LOG"
FAIL=0
for pid in "${PIDS[@]}"; do wait "$pid" || FAIL=$((FAIL+1)); done
echo "[probe1] G2 done, nonzero_lanes=$FAIL $(date)" >> "$LOG"

# G3: 12 параллельных lanes (b1/b2/b3 × r1-r4)
PIDS=()
for arm3 in b1 b2 b3; do
  for r in 1 2 3 4; do
    timeout 2400 php scripts/probe1/driver.php g3 "$arm3" "$r" /tmp/probe1_run > "/tmp/probe1_run/g3_${arm3}_${r}.lane" 2>&1 &
    PIDS+=($!)
  done
done
echo "[probe1] G3 lanes launched: ${#PIDS[@]}" >> "$LOG"
FAIL=0
for pid in "${PIDS[@]}"; do wait "$pid" || FAIL=$((FAIL+1)); done
echo "[probe1] G3 done, nonzero_lanes=$FAIL $(date)" >> "$LOG"

# Механистические пробы (после G2-B): эквивалентность + зависимость
for r in 1 2 3 4; do
  timeout 300 php scripts/probe1/driver.php probe "$r" /tmp/probe1_run >> "$LOG" 2>&1
done
echo "[probe1] DONE $(date)" >> "$LOG"
