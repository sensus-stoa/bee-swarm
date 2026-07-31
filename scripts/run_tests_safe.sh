#!/bin/bash
# run_tests_safe.sh — run each test file individually, kill on high CPU
cd ~/.bee_swarm
FAILED=()
PASSED=0
TIMEOUT=60
CPU_LIMIT=90  # kill if CPU > 90%

for f in tests/*Test.php; do
    echo "=== $(date +%H:%M:%S) $f ==="
    
    # Run in background, capture PID
    timeout $TIMEOUT vendor/bin/phpunit "$f" > /tmp/test_out.txt 2>&1 &
    PID=$!
    
    # Monitor CPU every 2 seconds
    while kill -0 $PID 2>/dev/null; do
        CPU=$(ps -p $PID -o %cpu --no-headers 2>/dev/null | tr -d ' ')
        if [ -n "$CPU" ] && [ "${CPU%.*}" -gt $CPU_LIMIT ]; then
            echo "HIGH CPU: ${CPU}% — KILLING"
            kill -9 $PID 2>/dev/null
            break
        fi
        sleep 2
    done
    
    wait $PID 2>/dev/null
    RC=$?
    
    if [ $RC -eq 0 ]; then
        echo "  PASS"
        PASSED=$((PASSED+1))
    elif [ $RC -eq 124 ]; then
        echo "  TIMEOUT — SKIPPED"
        FAILED+=("$f (timeout)")
    else
        echo "  FAILED (exit $RC)"
        echo "  --- output:"
        tail -5 /tmp/test_out.txt
        FAILED+=("$f")
    fi
done

echo "=== DONE ==="
echo "Passed: $PASSED"
echo "Failed: ${#FAILED[@]}"
for f in "${FAILED[@]}"; do echo "  $f"; done
