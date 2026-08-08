# CV→0 Bee Swarm — Deployment & Run Guide

> 31.07.2026 | For an AI agent or a human on a clean machine

## Requirements

- PHP 8.1+ (cli, sqlite3, mbstring, pdo)
- Composer
- git (optional, for updates)
- ~500 MB free RAM
- Linux (preferred) or macOS

## 0. Pre-setup: install PHP and Composer (if not installed)

### Linux (Debian/Ubuntu)

```bash
# Install PHP + extensions
sudo apt update
sudo apt install -y php-cli php-sqlite3 php-mbstring php-xml unzip curl

# Verify version (must be ≥8.1)
php -v

# Install Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --quiet
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php

# Verify
composer --version
```

### macOS

```bash
# Install Homebrew if missing
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install PHP
brew install php@8.2

# Install Composer
brew install composer

# Verify
php -v && composer --version
```

### Windows

```powershell
# Download and install PHP 8.2 from https://windows.php.net/download/
# Choose the "Thread Safe" zip, extract to C:\php
# Add C:\php to PATH (System Properties → Environment Variables)

# Install Composer from https://getcomposer.org/Composer-Setup.exe

# Verify
php -v
composer --version
```

## 1. Installation

```bash
# Clone the repository
git clone https://github.com/sensus-stoa/bee-swarm.git
cd bee-swarm

# Install dependencies
composer install --no-dev --optimize-autoloader

# Verify environment
php -r "echo 'PHP: ' . PHP_VERSION . PHP_EOL;"
php -r "echo 'SQLite: ' . (extension_loaded('pdo_sqlite') ? 'yes' : 'NO') . PHP_EOL;"
```

## 2. Test check (optional but recommended)

```bash
cd ~/bee-swarm

# Quick check: core tests only (no integration)
vendor/bin/phpunit tests/AtomRegistryTest.php tests/SearchTest.php tests/BeeTest.php

# Full suite (slow, ~2 minutes)
vendor/bin/phpunit tests/ --exclude-group disabled
```

Expected: `OK (XXX tests, XXX assertions)`, 0 failures, possibly 1 skipped.

## 3. Running the daemon

```bash
cd ~/bee-swarm

# Run in background with logging
nohup php agenda.php > /dev/null 2>&1 &

# Or run directly (Ctrl+C to stop)
php agenda.php
```

The daemon runs the loop: law search → plateau detection → forager scan → repeat.

## 4. Monitoring

```bash
# Watch the log in real time
tail -f ~/bee-swarm/logs/agenda.log

# Key log events:
#   🔍 — new law discovered
#   🏔️ PLATEAU — system in waiting mode
#   ROUTE — task routed to a bee
#   DEATH — a bee died (energy = 0)
#   SPAWN — a new bee was born
#   GEN — generation change
#   DUPLICATE — law already known
#   OVERFIT — candidate rejected by held-out check
#   INSUFFICIENT_DATA — not enough data for search

# Check the process is alive
pgrep -f agenda.php && echo "RUNNING" || echo "STOPPED"
```

## 5. Stage 0 verification

```bash
cd ~/bee-swarm

# Run all Stage 0 checks
php scripts/verify/verify_all.php

# With log file:
php scripts/verify/verify_all.php --log=logs/agenda.log
```

Expected: `9/9 PASS` or `8/9 PASS` (verify_0_8 may be SKIP if overlap data <10 pairs).

## 6. Database checks

```bash
cd ~/bee-swarm

# How many laws were discovered
php -r "
require 'vendor/autoload.php';
\$db = BeeSwarm\Infra\Database::get();
\$count = \$db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
echo \"Laws: \$count\n\";
"

# How many overlap records (if daemon is running)
php -r "
require 'vendor/autoload.php';
\$db = BeeSwarm\Infra\Database::get();
\$overlap = \$db->query('SELECT COUNT(*) FROM overlap_log')->fetchColumn();
echo \"Overlap records: \$overlap\n\";
"
```

## 7. Expected results after 24 hours

After 24 hours of continuous operation:

- ≥5 new laws (🔍 in log)
- ≥3 spawn events (SPAWN in log)
- ≥1 death event (DEATH in log)
- ≥10 overlap records (overlap_log in DB)
- verify_all.php → 8-9/9 PASS

## 8. Troubleshooting

| Symptom | Check |
|---------|-------|
| Daemon won't start | `php -l agenda.php` — syntax errors? |
| SQLite error | `ls -la data/swarm.db` — does the DB file exist, write permissions? |
| Search too slow | Normal with large grammars — search depth is bounded |
| Memory grows | Limit: `export PHP_MEMORY_LIMIT=512M` before starting |
| No overlap records | Normal for the first hours — data accumulates |

## 9. Stopping

```bash
# Graceful stop
pkill -f agenda.php

# Hard kill (if unresponsive)
pkill -9 -f agenda.php
```

## 10. Transferring results

Copy for analysis:

```bash
cp ~/bee-swarm/logs/agenda.log /media/flash/
cp ~/bee-swarm/data/swarm.db /media/flash/
php ~/bee-swarm/scripts/verify/verify_all.php > /media/flash/verify_results.txt
```
