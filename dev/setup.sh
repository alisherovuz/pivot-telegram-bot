#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .dev storage/private storage/cache dist
export BUILDX_CONFIG="$PWD/.dev/buildx"
ca_file="${CODEX_PROXY_CERT:-/etc/ssl/certs/ca-certificates.crt}"
docker build --secret "id=proxy_ca,src=$ca_file" -t pivot-php:8.3 -f dev/Dockerfile .
if ! docker network inspect pivot-dev >/dev/null 2>&1; then docker network create pivot-dev; fi
if ! docker container inspect pivot-db >/dev/null 2>&1; then
 docker run -d --name pivot-db --network pivot-dev -v pivot-dev-db:/var/lib/mysql -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -e MARIADB_DATABASE=pivot mariadb:11.4
else docker start pivot-db >/dev/null; fi
for attempt in $(seq 1 30); do
 if docker exec pivot-db mariadb-admin ping --silent >/dev/null 2>&1; then break; fi
 sleep 1
done
docker exec pivot-db mariadb-admin ping --silent
./dev/run composer install --no-interaction --prefer-source --optimize-autoloader
if [[ ! -e config/local.php ]]; then
 cat > config/local.php <<'PHP'
<?php
return [
 'dsn'=>'mysql:host=pivot-db;dbname=pivot;charset=utf8mb4', 'db_user'=>'root','db_password'=>'',
 'bot_token'=>'LOCAL_FAKE_TRANSPORT','webhook_secret'=>'local-only-webhook-secret-0000000000000000',
 'base_url'=>'http://127.0.0.1:8080','channel'=>'@pivotuzbekistan','storage'=>dirname(__DIR__).'/storage',
 'session_secure'=>false,'telegram_mode'=>'fake',
];
PHP
 chmod 600 config/local.php
fi
# Import only into an empty development database; never overwrite existing data.
if [[ "$(docker exec pivot-db mariadb -N -uroot -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='pivot'")" == 0 ]]; then
 docker exec -i pivot-db mariadb -uroot pivot < database/schema.sql
fi
./dev/run php -r 'require "app/bootstrap.php"; echo "Database ready; mode: ".$config["telegram_mode"].PHP_EOL;'
