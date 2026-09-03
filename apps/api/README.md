# hore.my API

## Purpose

This directory contains the Laravel backend application for hore.my. It is the deployment boundary for the modular monolith established by [ADR-0001](../../docs/adr/0001-modular-monolith-architecture.md).

This bootstrap contains framework foundations only. It does not implement product APIs, authentication, business modules, AI/OCR, MyInvois, Redis/Horizon, or domain behavior.

## Owner

The backend is owned through the repository [`CODEOWNERS`](../../CODEOWNERS) policy.

## Public interface

No product API contract has been introduced. Laravel's generated root route is retained only as a framework bootstrap artifact and is not an approved hore.my product endpoint.

## Dependencies

- PHP 8.3 or later within Laravel 13's supported PHP range
- Composer 2
- Laravel 13
- PostgreSQL is the intended project database but is not provisioned or required for the bootstrap verification tests.

Exact production runtime and infrastructure versions remain deferred by [ADR-0002](../../docs/adr/0002-technology-stack-selection.md).

## Local validation

From `apps/api`:

```text
php artisan --version
php artisan about
php artisan test
composer validate --strict
```

Do not run the Composer `setup` script until a local PostgreSQL environment has been intentionally configured. It creates a local `.env`, application key, and runs migrations.
