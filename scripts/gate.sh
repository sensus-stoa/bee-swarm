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

echo "=== LINT (php -l syntax) ==="
check "php -l all staged PHP" bash -c 'git diff --cached --name-only 2>/dev/null | grep "\.php$" | xargs -r php -l 2>&1 | grep -v "No syntax errors" | grep -v "^$"'

echo "=== STATIC ANALYSIS (psalm) ==="
check "psalm level 5" bash -c 'vendor/bin/psalm --no-progress 2>/dev/null | grep -q "No errors found"'

echo "=== RNG ISOLATION (fast guard) ==="
check "RNG guard clean" bash -c 'php -r "require \"vendor/autoload.php\"; if (\BeeSwarm\Infra\RngIsolation::hasUnrestoredGuards()) { echo \"UNRESTORED_GUARDS\"; exit(1); } echo \"CLEAN\";" | grep -q CLEAN'

echo "=== BEHAVIORAL DIVERSITY (30s) ==="
check "behavioral-diversity suite" bash -c 'vendor/bin/phpunit tests/BehavioralDiversityTest.php --no-progress 2>&1 | grep -q "^OK"'

echo "=== FULL SUITE ==="
check "phpunit --exclude-group disabled" bash -c 'vendor/bin/phpunit tests/ --exclude-group disabled --no-progress 2>&1 | grep -q "^OK"'

echo "=== PRODUCTION SMOKE (simulated) ==="
check "smoke test pass" bash -c 'php scripts/smoke.php 2>&1 | grep -q "SMOKE TEST: PASS"'

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
