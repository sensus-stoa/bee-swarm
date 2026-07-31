#!/bin/bash
# scripts/deploy-prod.sh — copy changed source files to EvoFamily/prod/
# Run after git commit. Preserves directory structure.
# Overwrites files in prod/ (does NOT delete the folder).
# Usage: bash scripts/deploy-prod.sh

PROD_DIR="$HOME/Documents/the_lair/EvoFamily/prod"
BEE_DIR="$HOME/.bee_swarm"

# Get files changed in last commit (excluding tests, docs, logs, data)
CHANGED=$(cd "$BEE_DIR" && git diff --name-only HEAD~1 2>/dev/null | grep -v '^tests/' | grep -v '^logs/' | grep -v '^data/' | grep -v '.md$' | grep -v '^.phpunit')

if [ -z "$CHANGED" ]; then
    echo "No source files changed in last commit."
    exit 0
fi

echo "Deploying to $PROD_DIR:"
for f in $CHANGED; do
    src="$BEE_DIR/$f"
    dst="$PROD_DIR/$f"
    if [ -f "$src" ]; then
        mkdir -p "$(dirname "$dst")"
        cp "$src" "$dst"
        echo "  $f"
    fi
done

echo ""
echo "Done. Next:"
echo "  1. cd ~/Documents/the_lair && git add EvoFamily/prod/ && git commit -m 'prod: deploy $(date +%Y-%m-%d)'"
echo "  2. git push"
echo "  3. Laptop: git pull → apply files from prod/ → rm applied files"
