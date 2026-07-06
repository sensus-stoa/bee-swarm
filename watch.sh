#!/bin/bash
# watch.sh — мониторинг роя
LOG_DIR="~/.bee_swarm/logs"

echo "═══ РОЙ ═══"
echo ""

if [ -f "$LOG_DIR/agenda.log" ]; then
    echo "📋 ДЕМОН (последние 10 строк):"
    tail -10 "$LOG_DIR/agenda.log"
else
    echo "📋 ДЕМОН: лог пуст"
fi

echo ""
echo "📊 ЗАКОНЫ:"
php -r "
require '~/.bee_swarm/vendor/autoload.php';
\$db = BeeSwarm\\Infra\\Database::get();
\$total = \$db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
echo '  Всего: ' . \$total . PHP_EOL;
\$recent = \$db->query('SELECT name, cv, domain FROM laws ORDER BY rowid DESC LIMIT 3')->fetchAll();
foreach (\$recent as \$l) echo '    ' . \$l['name'] . ' CV=' . round(\$l['cv'],4) . ' [' . \$l['domain'] . ']' . PHP_EOL;
" 2>/dev/null

echo ""
echo "📚 ЗНАНИЯ:"
php -r "
require '~/.bee_swarm/vendor/autoload.php';
echo '  Фактов: ' . BeeSwarm\\Infra\\Database::get()->query('SELECT COUNT(*) FROM knowledge_graph')->fetchColumn() . PHP_EOL;
" 2>/dev/null

echo ""
echo "💓 ПРОЦЕССЫ:"
pgrep -f agenda.php && echo "  Демон PID: $(pgrep -f agenda.php)" || echo "  Демон: НЕ ЗАПУЩЕН"
