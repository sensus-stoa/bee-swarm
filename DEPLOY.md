# CV→0 Bee Swarm — Инструкция по развёртыванию и запуску

> 31.07.2026 | Для AI-агента или человека на чистой машине

## Требования

- PHP 8.1+ (cli, sqlite3, mbstring, pdo)
- Composer
- git (опционально, для обновлений)
- Свободно ~500 MB RAM
- Linux (предпочтительно) или macOS

## 0. Pre-setup: установка PHP и Composer (если не установлены)

### Linux (Debian/Ubuntu)

```bash
# Установить PHP + расширения
sudo apt update
sudo apt install -y php-cli php-sqlite3 php-mbstring php-xml unzip curl

# Проверить версию (должна быть ≥8.1)
php -v

# Установить Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --quiet
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php

# Проверить
composer --version
```

### macOS

```bash
# Установить Homebrew если нет
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Установить PHP
brew install php@8.2

# Установить Composer
brew install composer

# Проверить
php -v && composer --version
```

### Windows

```powershell
# Скачать и установить PHP 8.2 с https://windows.php.net/download/
# Выбрать "Thread Safe" zip, распаковать в C:\php
# Добавить C:\php в PATH (System Properties → Environment Variables)

# Установить Composer с https://getcomposer.org/Composer-Setup.exe

# Проверить
php -v
composer --version
```

## 1. Установка

```bash
# Скопировать папку .bee_swarm с флешки в домашнюю директорию
cp -r /media/flash/.bee_swarm ~/
cd ~/.bee_swarm

# Установить зависимости
composer install --no-dev --optimize-autoloader

# Проверить что всё работает
php -r "echo 'PHP: ' . PHP_VERSION . PHP_EOL;"
php -r "echo 'SQLite: ' . (extension_loaded('pdo_sqlite') ? 'yes' : 'NO') . PHP_EOL;"
```

## 2. Проверка тестами (опционально, но рекомендуется)

```bash
cd ~/.bee_swarm

# Быстрая проверка: только core-тесты (без интеграционных)
vendor/bin/phpunit tests/AtomRegistryTest.php tests/SearchTest.php tests/BeeTest.php

# Полный набор (медленно, ~2 минуты)
vendor/bin/phpunit tests/ --exclude-group disabled
```

Ожидаемый результат: `OK (XXX tests, XXX assertions)`, 0 failures, может быть 1 skipped.

## 3. Запуск демона

```bash
cd ~/.bee_swarm

# Запустить в фоне с логированием
nohup php agenda.php > /dev/null 2>&1 &

# Или запустить напрямую (Ctrl+C чтобы остановить)
php agenda.php
```

Демон запустит цикл: поиск законов → plateau detection → forager scan → repeat.

## 4. Мониторинг

```bash
# Смотреть лог в реальном времени
tail -f ~/.bee_swarm/logs/agenda.log

# Ключевые события в логе:
#   🔍 — открыт новый закон
#   🏔️ PLATEAU — система в режиме ожидания
#   ROUTE — задача направлена пчеле
#   DEATH — пчела умерла (энергия = 0)
#   SPAWN — родилась новая пчела
#   GEN — смена поколения
#   DUPLICATE — закон уже известен
#   OVERFIT — кандидат отвергнут held-out проверкой
#   INSUFFICIENT_DATA — недостаточно данных для поиска

# Проверить что процесс жив
pgrep -f agenda.php && echo "RUNNING" || echo "STOPPED"
```

## 5. Верификация Stage 0

```bash
cd ~/.bee_swarm

# Запустить все 9 проверок Stage 0
php scripts/verify/verify_all.php

# С лог-файлом:
php scripts/verify/verify_all.php --log=logs/agenda.log
```

Ожидаемый результат: `9/9 PASS` или `8/9 PASS` (verify_0_8 может быть SKIP если overlap-данных <10 пар).

## 6. Проверка БД

```bash
cd ~/.bee_swarm

# Сколько законов открыто
php -r "
require 'vendor/autoload.php';
\$db = BeeSwarm\Infra\Database::get();
\$count = \$db->query('SELECT COUNT(*) FROM laws')->fetchColumn();
echo \"Laws: \$count\n\";
"

# Сколько пчёл в популяции (если запущен)
php -r "
require 'vendor/autoload.php';
\$db = BeeSwarm\Infra\Database::get();
\$overlap = \$db->query('SELECT COUNT(*) FROM overlap_log')->fetchColumn();
echo \"Overlap records: \$overlap\n\";
"
```

## 7. Ожидаемые результаты за 24 часа

После 24 часов непрерывной работы:

- ≥5 новых законов (🔍 в логе)
- ≥3 spawn events (SPAWN в логе)
- ≥1 death event (DEATH в логе)
- ≥10 overlap-записей (overlap_log в БД)
- verify_all.php → 8-9/9 PASS

## 8. Диагностика проблем

| Симптом | Проверка |
|---------|---------|
| Демон не запускается | `php -l agenda.php` — нет ли синтаксических ошибок |
| Ошибка SQLite | `ls -la data/swarm.db` — существует ли файл БД, права на запись |
| Поиск слишком медленный | Это нормально при 551 ops в grammar — глубина поиска 2 |
| Память растёт | Ограничить: `export PHP_MEMORY_LIMIT=512M` перед запуском |
| Нет overlap-записей | Нормально для первых часов — данные накапливаются |

## 9. Остановка

```bash
# Мягкая остановка
pkill -f agenda.php

# Жёсткая (если не отвечает)
pkill -9 -f agenda.php
```

## 10. Передача результатов

Скопировать на флешку для анализа:

```bash
cp ~/.bee_swarm/logs/agenda.log /media/flash/
cp ~/.bee_swarm/data/swarm.db /media/flash/
php ~/.bee_swarm/scripts/verify/verify_all.php > /media/flash/verify_results.txt
```
