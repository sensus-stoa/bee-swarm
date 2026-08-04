#!/bin/bash
# scripts/deploy.sh — SAFE DEPLOY WITH TEST GATE
#
# НЕЛЬЗЯ задеплоить без пройденных тестов.
# Этот скрипт — механический предохранитель.
#
# Использование:
#   bash scripts/deploy.sh          # деплой всех изменённых файлов
#   bash scripts/deploy.sh --force  # деплой без тестов (только экстренно)

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

LAPTOP="hive@192.168.1.105"
KEY="$HOME/.ssh/id_ed25519_laptop"

echo -e "${YELLOW}══════════════════════════════════════════${NC}"
echo -e "${YELLOW}  DEPLOY GATE${NC}"
echo -e "${YELLOW}══════════════════════════════════════════${NC}"

# ── GATE 1: Full test suite ──────────────────────────
if [ "${1:-}" != "--force" ]; then
    echo ""
    echo -e "${YELLOW}[GATE 1/3] Full pipeline regression test...${NC}"
    
    if vendor/bin/phpunit tests/FullPipelineRegressionTest.php --no-output 2>/dev/null; then
        echo -e "${GREEN}  PASS — regression test${NC}"
    else
        echo -e "${RED}  FAIL — regression test. Fix before deploying.${NC}"
        echo -e "${RED}  Run: vendor/bin/phpunit tests/FullPipelineRegressionTest.php${NC}"
        echo ""
        echo -e "${RED}  EMERGENCY OVERRIDE: bash scripts/deploy.sh --force${NC}"
        exit 1
    fi
    
    echo -e "${YELLOW}[GATE 2/3] Unit tests for changed files...${NC}"
    CHANGED=$(git diff --name-only HEAD | grep '\.php$' | sed 's|src/|tests/|' | sed 's|\.php|Test.php|' || true)
    if [ -n "$CHANGED" ]; then
        for f in $CHANGED; do
            if [ -f "$f" ]; then
                vendor/bin/phpunit "$f" --no-output 2>/dev/null && echo -e "${GREEN}  $f PASS${NC}" || {
                    echo -e "${RED}  $f FAIL${NC}"
                    exit 1
                }
            fi
        done
    fi
else
    echo -e "${RED}  ⚠️  --force: SKIPPING ALL TEST GATES${NC}"
fi

# ── GATE 3: RNG clean ──────────────────────────────
echo -e "${YELLOW}[GATE 3/3] RNG poison check...${NC}"
if php -r 'require "vendor/autoload.php"; \BeeSwarm\Infra\RngIsolation::hasUnrestoredGuards() && exit(1);' 2>/dev/null; then
    echo -e "${GREEN}  RNG clean${NC}"
else
    echo -e "${RED}  RNG POISONED — srand without restore${NC}"
    exit 1
fi

# ── DEPLOY ──────────────────────────────────────────
echo ""
echo -e "${GREEN}All gates passed. Deploying...${NC}"

FILES=$(git diff --name-only HEAD | grep '\.php$' || true)
if [ -z "$FILES" ]; then
    echo "No PHP files changed. Nothing to deploy."
    exit 0
fi

for f in $FILES; do
    echo "  $f"
    scp -i "$KEY" "$f" "${LAPTOP}:~/.bee_swarm/$f" 2>/dev/null
done

echo ""
echo -e "${GREEN}DEPLOY DONE. Restart hive on laptop to apply.${NC}"
