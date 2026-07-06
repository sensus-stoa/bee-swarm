#!/bin/bash
# Stage 0 — Verify ALL 7 criteria
PASS=0
FAIL=0
for SCRIPT in "$(dirname "$0")"/verify_0_*.sh; do
    [ "$SCRIPT" = "$0" ] && continue
    echo "=== $(basename "$SCRIPT") ==="
    if bash "$SCRIPT"; then
        PASS=$((PASS+1))
    else
        FAIL=$((FAIL+1))
    fi
    echo
done
echo "=== STAGE 0: $PASS/7 passed, $FAIL failed ==="
if [ "$FAIL" -eq 0 ]; then
    echo '{"stage":"0","pass":true,"criteria_passed":7,"criteria_failed":0}'
    exit 0
else
    echo '{"stage":"0","pass":false,"criteria_passed":'$PASS',"criteria_failed":'$FAIL'}'
    exit 1
fi

