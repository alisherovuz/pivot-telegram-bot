#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker start pivot-db >/dev/null
if docker container inspect pivot-web >/dev/null 2>&1; then docker start pivot-web >/dev/null;
else docker run -d --name pivot-web --user "$(id -u):$(id -g)" --network pivot-dev -p 127.0.0.1:8080:8080 -v "$PWD:/app" pivot-php:8.3 php -S 0.0.0.0:8080 -t public; fi
for attempt in $(seq 1 20); do
 if curl --noproxy 127.0.0.1 -fsS 'http://127.0.0.1:8080/?page=login' -o /tmp/pivot-login-check.html && rg -q 'Sign in to Pivot' /tmp/pivot-login-check.html; then
  echo 'Local admin login responds. Run dev/run php bin/cron.php for a bounded fake-transport worker pass.'; exit 0
 fi
 sleep 1
done
exit 1
