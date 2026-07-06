#!/bin/bash
# Stage 0 Criterion 1.5: Plateau Honesty
# Checks: PLATEAU appears in log (system detects idle state)
LOGFILE="$HOME/.bee_swarm/logs/agenda.log"

PLATEAU_COUNT=$(grep -c 'PLATEAU' "$LOGFILE" 2>/dev/null || echo 0)

echo "PLATEAU events: $PLATEAU_COUNT (must be > 0)"

if [ "$PLATEAU_COUNT" -gt 0 ]; then
    echo '{"pass":true,"criterion":"1.5 Plateau Honesty"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.5 Plateau Honesty","plateau_count":0}'
    exit 1
fi

