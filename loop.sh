#!/bin/bash
# ~/.bee_swarm/loop.sh
# ЗАМКНУТЫЙ ЦИКЛ: decide → domain → validate → self-replace
# Запускать в cron: */5 * * * * ~/.bee_swarm/loop.sh

BASE="http://127.0.0.1:8765"
LOG="/tmp/roe_loop.log"

# 1. Само-описание → решение
DECISION=$(curl -s "$BASE/decide" 2>/dev/null)
if [ -z "$DECISION" ]; then
    echo "[$(date)] Server down, restarting..." >> "$LOG"
    cd ~/.bee_swarm && ./rr serve > /dev/null 2>&1 &
    exit 0
fi

ACTION=$(echo "$DECISION" | python3 -c "import json,sys;d=json.load(sys.stdin);print(d['decision']['action'])" 2>/dev/null)
echo "[$(date)] Decision: $ACTION" >> "$LOG"

# 2. Исполнить решение
case "$ACTION" in
    explore_domain)
        DOMAIN=$(echo "$DECISION" | python3 -c "import json,sys;print(json.load(sys.stdin)['decision']['domain'])" 2>/dev/null)
        # Генерируем данные и отправляем в /domain
        curl -s -X POST "$BASE/domain" -H "Content-Type: application/json" \
          -d "{\"domain\":\"$DOMAIN\",\"tasks\":{\"auto_$DOMAIN\":[[1,2,3],[2,3,5],[3,4,7]]}}" >> "$LOG" 2>&1
        ;;
    expand_grammar)
        curl -s -X POST "$BASE/domain" -H "Content-Type: application/json" \
          -d '{"domain":"grammar_test","tasks":{"MIN":[[0,0,0],[2,3,2],[5,1,1],[4,4,4]]}}' >> "$LOG" 2>&1
        ;;
    validate_paradigms)
        curl -s "$BASE/validate" >> "$LOG" 2>&1
        ;;
    learn_facts)
        curl -s -X POST "$BASE/learn" -H "Content-Type: application/json" \
          -d '{"sentence":"автономный рой — это самообучающаяся система"}' >> "$LOG" 2>&1
        ;;
    virtuous_quest)
        curl -s "$BASE/paradigm" >> "$LOG" 2>&1
        ;;
esac

# 3. Само-замена (раз в час)
if [ $(( $(date +%M) / 60 )) -eq 0 ]; then
    php ~/.bee_swarm/self_replace.php >> "$LOG" 2>&1
fi

# 4. Итог в лог
echo "[$(date)] Cycle complete, bees: $(curl -s "$BASE/status" | python3 -c 'import json,sys;print(json.load(sys.stdin).get("laws","?"))' 2>/dev/null)" >> "$LOG"
