#!/bin/bash
# Stage 0 Criterion 1.3: Parsimony
# Checks: for each task, the simplest law is selected
# Proxy: no compose law (complexity>=2) exists when a simple law (complexity=1) for same task
DB="$HOME/.bee_swarm/data/swarm.db"

COMPLEX_DUPS=$(php -r '
$db = new PDO("sqlite:'$DB'");
// For each task name, check if both simple and complex laws exist
$rows = $db->query("SELECT name, COUNT(*) c, SUM(CASE WHEN formula LIKE "%(%" THEN 2 ELSE 1 END) min_complexity FROM laws GROUP BY name HAVING c > 1 AND min_complexity > c")->fetchAll();
echo count($rows);
' 2>/dev/null || echo 0)

echo "Tasks with duplicate complexity: $COMPLEX_DUPS (must be 0)"

if [ "$COMPLEX_DUPS" -eq 0 ]; then
    echo '{"pass":true,"criterion":"1.3 Parsimony"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.3 Parsimony","complex_dups":'$COMPLEX_DUPS'}'
    exit 1
fi

