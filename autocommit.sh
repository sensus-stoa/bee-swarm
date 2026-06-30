#!/bin/bash
# Авто-коммит изменений кода роя
cd ~/.bee_swarm
git add -A
if git diff --cached --quiet; then
    echo "Нет изменений"
else
    git commit -m "auto: $(date '+%d.%m.%Y %H:%M')"
    echo "Зафиксировано: $(git log --oneline -1)"
fi
