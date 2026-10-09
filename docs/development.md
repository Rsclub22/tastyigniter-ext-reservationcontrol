# Development

The Docker files in this repository (`Dockerfile.dev`, `docker-compose.dev.yml`) are
**for development only**. The extension does not need them to run in production; install it
through Composer into a normal TastyIgniter 4 site.

## Prerequisites

Docker with the Compose v2 plugin (`docker compose`, no hyphen). No local PHP, Composer or
database is required.

If your user id is not 1000, export it first so files created in the container belong to you:

```bash
export DEV_UID=$(id -u) DEV_GID=$(id -g)
```

## Build

```bash
docker compose -f docker-compose.dev.yml build php
```

The image is PHP 8.3 with `intl`, `zip`, `gd`, `bcmath`, `pdo_mysql`, Composer 2 and a
`memory_limit` of 1G. A
MariaDB 10.11 service (`db`, database `testbench`, user `forge`) starts automatically with
the first command and keeps its data in memory only.

## Commands

Every tool runs through the container:

```bash
# Install dependencies
docker compose -f docker-compose.dev.yml run --rm php composer install

# Tests
docker compose -f docker-compose.dev.yml run --rm php vendor/bin/pest

# Code style (fix / check only)
docker compose -f docker-compose.dev.yml run --rm php vendor/bin/pint
docker compose -f docker-compose.dev.yml run --rm php vendor/bin/pint --test

# Static analysis
docker compose -f docker-compose.dev.yml run --rm php vendor/bin/phpstan analyse
```

A clean run reports `No errors`. The `--memory-limit=1G` flag is no longer needed: the base
image ships no `php.ini`, so `memory_limit` was the 128M default and PHPStan's worker
processes died with `Child process error (exit code 255)` before reporting anything. The
image now sets 1G in `Dockerfile.dev`, so rebuild it if you still see that error.

Stop the database when you are done:

```bash
docker compose -f docker-compose.dev.yml down
```

## Database

The tests need MariaDB/MySQL, not SQLite: TastyIgniter's own migrations create indexes with
names that collide across tables, which SQLite rejects (`index coupon_id_index already
exists`). The connection settings come from environment variables in
`docker-compose.dev.yml` (`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`), so `phpunit.xml.dist` must not override them.
