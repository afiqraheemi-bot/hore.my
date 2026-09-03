# hore.my API

## Purpose

This directory contains the Laravel backend application for hore.my. It is the deployment boundary for the modular monolith established by [ADR-0001](../../docs/adr/0001-modular-monolith-architecture.md).

This bootstrap contains framework foundations only. It does not implement product APIs, authentication, business modules, AI/OCR, MyInvois, Redis/Horizon, or domain behavior.

## Owner

The backend is owned through the repository [`CODEOWNERS`](../../CODEOWNERS) policy.

## Public interface

No product API contract has been introduced. Laravel's generated root route is retained only as a framework bootstrap artifact and is not an approved hore.my product endpoint.

## Dependencies

- PHP 8.3, 8.4, or 8.5
- Composer 2.10.2 or newer within the Composer 2 major line
- Laravel 13
- PostgreSQL is the intended project database but is not provisioned or required for the bootstrap verification tests.

Patch versions are not pinned to a developer workstation. Deployments and development environments must use supported, security-maintained patch releases within the declared range. Exact production infrastructure versions remain deferred by [ADR-0002](../../docs/adr/0002-technology-stack-selection.md).

### Required PHP extensions

The backend runtime contract requires:

- `ctype`
- `dom`
- `fileinfo`
- `filter`
- `hash`
- `iconv`
- `json`
- `libxml`
- `mbstring`
- `openssl`
- `pcre`
- `PDO`
- `pdo_pgsql`
- `Phar`
- `session`
- `tokenizer`
- `xml`
- `xmlwriter`

These capabilities are declared in `composer.json`. Composer's platform check must remain enabled so installation fails when the active PHP runtime cannot satisfy them. PostgreSQL PDO support is mandatory even when an isolated test uses an in-memory database.

### Composer policy

`composer.lock` is committed and is the reproducible source for dependency installation. Use `composer install` for normal setup and verification; do not substitute an unconstrained dependency update.

The local Composer 2.10.0 executable does not meet this baseline: `composer diagnose` reported advisories against the Composer executable that are fixed from 2.10.2, as well as missing tag and development verification keys. Upgrading Composer and configuring its verification keys are separate machine-environment tasks and are not performed by this repository change.

## Local validation

From `apps/api`:

```text
php artisan --version
php artisan about
php artisan test
composer validate --strict
composer check-platform-reqs
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

Do not run the Composer `setup` script until a local PostgreSQL environment has been intentionally configured. It creates a local `.env`, application key, and runs migrations.

### Formatting and static analysis

- [Laravel Pint](https://laravel.com/docs/pint) formats PHP code to the default Laravel preset; no project-specific `pint.json` is committed because the preset already passes cleanly. Run `./vendor/bin/pint` to format, `./vendor/bin/pint --test` to check without writing.
- [Larastan](https://github.com/larastan/larastan) (PHPStan with Laravel-aware rules) is configured in [`phpstan.neon`](phpstan.neon) at level 9 (the strictest level PHPStan supports), scoped to `app/`. Run `./vendor/bin/phpstan analyse`.

## Docker development environment

A minimal Docker Compose environment (`api`, `web`, `postgres`, `redis`, `mailpit`) is defined at the repository root in [`docker-compose.yml`](../../docker-compose.yml). See [`docs/development/setup.md`](../../docs/development/setup.md) for usage. It is development-only and introduces no production infrastructure or architecture decision.
