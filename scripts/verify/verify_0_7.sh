#!/bin/bash
# Stage 0 Criterion 1.7: Compression Superiority
LOGFILE="$HOME/.bee_swarm/logs/agenda.log"
FAILS=$(grep -c 'COMPRESSION_FAIL' "$LOGFILE" 2>/dev/null || true)
FAILS=$(echo "$FAILS" | head -1 | tr -d '[:space:]')
FAILS=${FAILS:-0}
echo "COMPRESSION_FAIL events: $FAILS (must be 0)"
if [ "$FAILS" = "0" ]; then
    echo '{"pass":true,"criterion":"1.7 Compression Superiority"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.7 Compression Superiority"}'
    exit 1
fi
