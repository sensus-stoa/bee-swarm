#!/bin/bash
# Stage 0 Criterion 1.2: Statistical Sufficiency
LOGFILE="$HOME/.bee_swarm/logs/agenda.log"
REFUSED=$(grep -c 'DATA_REFUSED\|insufficient' "$LOGFILE" 2>/dev/null || true)
REFUSED=$(echo "$REFUSED" | head -1 | tr -d '[:space:]')
REFUSED=${REFUSED:-0}
echo "DATA_REFUSED events: $REFUSED (must be 0)"
if [ "$REFUSED" = "0" ]; then
    echo '{"pass":true,"criterion":"1.2 Statistical Sufficiency"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.2 Statistical Sufficiency"}'
    exit 1
fi
