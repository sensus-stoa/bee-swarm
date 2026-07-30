#!/bin/bash
# scripts/gate.sh — post-GREEN commit gate
# Запускать после GREEN, ДО коммита. Exit 0 = GO, Exit 1 = BLOCKED.
# Использование: bash scripts/gate.sh

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'
PASS=0

check() {
    local label="$1"
    shift
    if "$@" 2>&1; then
        echo -e "  ${GREEN}[PASS]${NC} $label"
    else
        echo -e "  ${RED}[FAIL]${NC} $label"
        PASS=1
    fi
}

echo "=== LINT (syntax) ==="
check "php -l" bash -c 'git diff --name-only HEAD 2>/dev/null | grep "\.php$" | xargs -r php -l | grep -v "No syntax errors"'

echo "=== LINT (psalm) ==="
check "psalm level 5" bash -c 'vendor/bin/psalm --no-progress 2>/dev/null | grep -q "No errors found"'

echo "=== FULL SUITE ==="
check "phpunit --exclude-group disabled" bash -c 'vendor/bin/phpunit tests/ --exclude-group disabled --no-progress 2>&1 | grep -q "^OK"'

echo "=== REVIEW ==="
REVIEW_MARKER=".hermes/review-pass.txt"
if [ ! -f "$REVIEW_MARKER" ]; then
    # Fallback: check if review was committed in last session
    echo -e "  ${RED}[FAIL]${NC} review marker not found ($REVIEW_MARKER)"
    echo "  Run: delegate_task review over git diff"
    PASS=1
else
    AGE=$(($(date +%s) - $(stat -c %Y "$REVIEW_MARKER" 2>/dev/null || echo 0)))
    if [ $AGE -gt 7200 ]; then
        echo -e "  ${RED}[FAIL]${NC} review stale (${AGE}s > 2h)"
        PASS=1
    else
        echo -e "  ${GREEN}[PASS]${NC} review (${AGE}s ago)"
    fi
fi

echo ""
if [ $PASS -eq 0 ]; then
    echo -e "${GREEN}=== ALL GATES PASS — ready to commit ===${NC}"
else
    echo -e "${RED}=== BLOCKED — fix failures above before commit ===${NC}"
fi
exit $PASS
