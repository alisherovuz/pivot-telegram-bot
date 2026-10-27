# Development and the managed cloud environment

Work in `/workspace/pivot-telegram-bot` on `main`. The cloud task already has an isolated checkout; use it directly. Do not create a Git worktree unless explicitly requested. Do not change other repositories.

The base cloud machine has Docker but no native PHP. The repository's development image contains PHP 8.3, required extensions and Composer; MariaDB runs on the private `pivot-dev` Docker network. The DB has no published port. The development-only DB uses no password on this private network; it must never be exposed publicly or copied to production configuration.

```sh
./dev/setup.sh
./dev/start.sh
./dev/run php bin/check.php
./dev/run php tests/run.php
```

Setup builds `dev/Dockerfile`, starts MariaDB, installs the locked dependencies via Composer's Git source mode, creates local fake-transport config only if absent, and imports SQL only into an empty development database. Running it again preserves application data and local configuration. It supplies the managed proxy CA without disabling TLS verification. Outside this managed environment, `CODEX_PROXY_CERT` can be omitted to use the system CA bundle.

The development helper uses `COMPOSER_HOME` in a writable temporary directory. Composer package versions are fixed by `composer.lock`. Initial installation required source mode because `api.github.com` archive requests were blocked; `github.com` source fetches succeeded. Composer's separate advisory audit needs `packagist.org`; its initial policy block is recorded in TESTING.md. Do not disable security checks or TLS verification to work around network failures.

The local development admin listens on port 8080 bound to loopback. Use local requests for readiness checks; this onboarding UI does not provide a public preview. There is no default production account. To create a local developer account, use `bin/create-admin.php` with a password read from stdin (the Docker helper is not interactive by default; use a local password pipe from a protected source or invoke Docker with `-it` for a hidden terminal prompt).

`telegram_mode=fake` has no HTTP capability. Bot tests manipulate the fake transport's membership/error states and store synthetic files. No real bot token, webhook registration, channel access or Telegram messages are required or permitted for these development checks.

## Test database and HTTP server

`tests/run.php` destroys and recreates **only `pivot_test`**, never the configured production/development database. Its local root DB credentials can be overridden by `PIVOT_TEST_DB_HOST`, `PIVOT_TEST_DB_USER` and `PIVOT_TEST_DB_PASSWORD`; those are test-only environment variables. Do not point the test runner at a production DB server. It leaves synthetic fixtures and `.dev/test-config.php` for HTTP tests. All test passwords are synthetic examples.

```sh
./dev/run php tests/run.php
# Start once; for subsequent runs, docker start pivot-test-web if stopped.
docker run -d --name pivot-test-web --user "$(id -u):$(id -g)" \
  --network pivot-dev -p 127.0.0.1:8081:8081 -v "$PWD:/app" \
  -e PIVOT_CONFIG=/app/.dev/test-config.php pivot-php:8.3 \
  php -S 0.0.0.0:8081 -t public
python3 tests/http_checks.py
./dev/run php -r 'putenv("PIVOT_CONFIG=/app/.dev/test-config.php"); require "bin/cron.php";'
```

The HTTP suite runs against this fake-transport server only, exercising real cookies, forms, role checks, webhook requests and downloads. Re-run the PHP fixture setup before repeating the full HTTP run. Tests produce PDFs/XLSX/result JSON under ignored `.dev/test-output`. The development web server is not a production server; use Ahost's PHP/Apache for deployment.

Optional browser checks require a local Chromium and `playwright-core`; install only into ignored `.dev/browser`. The automated browser check in `tests/browser.cjs` accepts `PIVOT_PLAYWRIGHT_MODULE` pointing to that installed module and `PIVOT_CHROMIUM` for the executable. It checks desktop/mobile overflow and records screenshots, with synthetic fixture login.

## Useful commands

```sh
./dev/run composer validate --strict
./dev/run composer audit
./dev/run sh -c 'find app public bin tests -name "*.php" -print0 | xargs -0 -n1 php -l'
./dev/run php bin/cron.php            # only after confirming local config uses fake transport
./dev/run php bin/package.php
(cd dist && sha256sum -c pivot-deployment.zip.sha256)
```

Restart only this project's services as needed. `docker stop pivot-web pivot-test-web pivot-db` stops them. Retained files/images/dependencies can survive environment snapshots; running processes and container networking must be checked/restarted in later tasks. New-task restoration and Ahost deployment are separate from the current-instance tests. Rebuild/recreate containers if the managed proxy or CA changes. Preserve DB volumes and local configuration when replacing containers. Review changes before any commit or push; setup/build commands do not publish application source to GitHub.
