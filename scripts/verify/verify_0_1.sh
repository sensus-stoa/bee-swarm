#!/bin/bash
# Stage 0 Criterion 1.1: Held-Out Validation
LOGFILE="$HOME/.bee_swarm/logs/agenda.log"
OVERFIT=$(grep 'OVERFIT' "$LOGFILE" 2>/dev/null | grep -v 'RETRO_OVERFIT' | grep -v '0 overfit' | wc -l || true)
OVERFIT=$(echo "$OVERFIT" | head -1 | tr -d '[:space:]')
OVERFIT=${OVERFIT:-0}
echo "OVERFIT events: $OVERFIT (must be 0)"
if [ "$OVERFIT" = "0" ]; then
    echo '{"pass":true,"criterion":"1.1 Held-Out Validation"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.1 Held-Out Validation"}'
    exit 1
fi
