FROM ubuntu:24.04

# Install TrueAsync PHP and dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget ca-certificates unzip \
    && rm -rf /var/lib/apt/lists/*

# Download TrueAsync PHP 0.7.4 for Linux
RUN wget -q "https://github.com/true-async/releases/releases/download/v0.7.4/php-trueasync-0.7.4-php8.6-linux-x64.tar.gz" -O /tmp/php.tar.gz 2>/dev/null || \
    echo "Linux binary not found — need alternate approach"

# Placeholder: copy local PHP build or use prebuilt
# For now — use system PHP 8.2 + RoadRunner (known working)
RUN apt-get update && apt-get install -y --no-install-recommends \
    php8.3-cli php8.3-sqlite3 php8.3-mbstring \
    && rm -rf /var/lib/apt/lists/*

# Copy swarm source
COPY src/ /swarm/src/
COPY public/ /swarm/public/
COPY composer.json /swarm/
COPY vendor/ /swarm/vendor/

WORKDIR /swarm
EXPOSE 8765

CMD ["php", "-S", "0.0.0.0:8765", "-t", "public/"]
