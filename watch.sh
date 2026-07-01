#!/bin/bash
# ~/.bee_swarm/watch.sh
# Просмотр логов роя в реальном времени

LOG_DIR="~/.bee_swarm/logs"
echo "═══ ЛОГИ РОЯ ═══"
echo ""

if [ -f "$LOG_DIR/agenda.log" ]; then
    echo "📋 ДЕМОН (последние 20 строк):"
    tail -20 "$LOG_DIR/agenda.log"
else
    echo "📋 ДЕМОН: лог пуст"
fi

echo ""
echo "📊 ЗАКОНЫ:"
php -r "
require '~/.bee_swarm/vendor/autoload.php';
\$db = BeeSwarm\Database::get();
\$total = \$db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
\$recent = \$db->query('SELECT name, cv, domain FROM laws ORDER BY rowid DESC LIMIT 5')->fetchAll();
echo \"  Всего: \$total\" . PHP_EOL;
echo '  Последние:' . PHP_EOL;
foreach (\$recent as \$l) echo '    ' . \$l['name'] . ' CV=' . round(\$l['cv'],4) . ' [' . \$l['domain'] . ']' . PHP_EOL;
" 2>/dev/null

echo ""
echo "📚 ЗНАНИЯ:"
php -r "
require '~/.bee_swarm/vendor/autoload.php';
echo '  Фактов: ' . BeeSwarm\Database::get()->query('SELECT COUNT(*) FROM knowledge_graph')->fetchColumn() . PHP_EOL;
" 2>/dev/null

echo ""
echo "🧬 ГЕНЕРАТОР:"
php -r "
require '~/.bee_swarm/vendor/autoload.php';
\$db = BeeSwarm\Database::get();
\$pool = \$db->query('SELECT COUNT(*) FROM action_pool')->fetchColumn();
\$trusted = \$db->query(\"SELECT COUNT(*) FROM action_pool WHERE source='trusted'\")->fetchColumn();
echo \"  Пул: \$pool шаблонов (trusted: \$trusted)\" . PHP_EOL;
" 2>/dev/null

echo ""
echo "💓 ПРОЦЕССЫ:"
ps aux | grep -E "agenda|rr serve" | grep -v grep | awk '{print "  " $11 " PID=" $2 " CPU=" $3 "% MEM=" $4 "%"}'

echo ""
echo "Логи: $LOG_DIR/"
ls -la "$LOG_DIR/" 2>/dev/null || echo "  (пусто)"
