#!/bin/bash
# ~/.bee_swarm/start.sh
# Запуск всей экосистемы роя после перезагрузки

cd ~/.bee_swarm

# Убить старые процессы если есть
kill $(lsof -t -i:8765) 2>/dev/null
pkill -f "agenda.php" 2>/dev/null
sleep 1

echo "[START] Запуск RoadRunner (HTTP API)..."
./rr serve > /tmp/rr.log 2>&1 &
sleep 2

echo "[START] Запуск демона AGI..."
php agenda.php > /tmp/agenda.log 2>&1 &

sleep 1
echo "[START] Готово. HTTP: :8765, Демон: фоновый."
echo "  Логи: /tmp/rr.log /tmp/agenda.log"
echo "  Статус: curl http://127.0.0.1:8765/status"
