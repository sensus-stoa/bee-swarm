#!/bin/bash
# EXP-045: full suite -> DEV matrix (laptop, detached launch)
set -u
cd ~/.bee_swarm
LOG=/tmp/exp045_chain.log
echo "[chain] start $(date)" > "$LOG"

echo "[chain][1/3] fast suite: paratest -p8" >> "$LOG"
timeout 3600 vendor/bin/paratest --processes=8 --configuration phpunit.xml >> "$LOG" 2>&1
FAST=$?
echo "[chain] fast exit=$FAST" >> "$LOG"
if [ "$FAST" -ne 0 ]; then echo "[chain] SUITE_FAST_FAIL" >> "$LOG"; exit 1; fi

echo "[chain][2/3] slow group" >> "$LOG"
timeout 3600 vendor/bin/phpunit --configuration phpunit.xml --group slow >> "$LOG" 2>&1
SLOW=$?
echo "[chain] slow exit=$SLOW" >> "$LOG"
if [ "$SLOW" -ne 0 ]; then echo "[chain] SUITE_SLOW_FAIL" >> "$LOG"; exit 1; fi

echo "[chain][3/3] DEV matrix: 5 arms x 3 seeds x 40s/task" >> "$LOG"
for arm in baseline random evolved shuffled noinherit; do
  for seed in 101 102 103; do
    EXP045_TASK_BUDGET=40 EXP045_POP_N=8 php scripts/exp045/driver.php "$arm" "$seed" /tmp/exp045_dev_families.json /tmp/exp045_dev_out >> "$LOG" 2>&1
  done
done
echo "[chain] DEV_DONE $(date)" >> "$LOG"
