#!/bin/bash
# Stage 0 Criterion 1.6: Deduplication
# Checks: no duplicate laws (same formula, different IDs)
DB="$HOME/.bee_swarm/data/swarm.db"

DUPS=$(php -r '
$db = new PDO("sqlite:'$DB'");
echo $db->query("SELECT COUNT(*) FROM (SELECT name, formula, COUNT(*) c FROM laws GROUP BY name, formula HAVING c > 1)")->fetchColumn();
' 2>/dev/null || echo 0)

echo "Duplicate laws: $DUPS (must be 0)"

if [ "$DUPS" -eq 0 ]; then
    echo '{"pass":true,"criterion":"1.6 Deduplication"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.6 Deduplication","duplicates":'$DUPS'}'
    exit 1
fi

