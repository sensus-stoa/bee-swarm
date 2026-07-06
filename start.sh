#!/bin/bash
# start.sh — запуск демона AGI
cd ~/.bee_swarm

pkill -f "agenda.php" 2>/dev/null
sleep 1

echo "[START] Запуск демона..."
FORAGER_SOURCES="~/ninjacat/Documents/the_lair/ExoCortex/Journal" php agenda.php > /tmp/agenda.log 2>&1 &

sleep 1
echo "[START] Готово. Лог: /tmp/agenda.log"
pgrep -f agenda.php && echo "  PID: $(pgrep -f agenda.php)" || echo "  НЕ ЗАПУСТИЛСЯ"
