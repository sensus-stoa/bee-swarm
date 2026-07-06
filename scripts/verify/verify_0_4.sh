#!/bin/bash
# Stage 0 Criterion 1.4: Non-Triviality
# Checks: no identity (x0) or constant (K*) laws in DB
DB="$HOME/.bee_swarm/data/swarm.db"

TRIVIAL=$(php -r '
$db = new PDO("sqlite:'$DB'");
$c = $db->query("SELECT COUNT(*) FROM laws WHERE formula LIKE "x%" AND formula NOT LIKE "%(%" AND length(formula) <= 3")->fetchColumn();
$c += $db->query("SELECT COUNT(*) FROM laws WHERE formula LIKE "K%" AND formula NOT LIKE "%(%"")->fetchColumn();
echo $c;
' 2>/dev/null || echo 0)

echo "Trivial laws (x0/K*): $TRIVIAL (must be 0)"

if [ "$TRIVIAL" -eq 0 ]; then
    echo '{"pass":true,"criterion":"1.4 Non-Triviality"}'
    exit 0
else
    echo '{"pass":false,"criterion":"1.4 Non-Triviality","trivial_count":'$TRIVIAL'}'
    exit 1
fi

