# Multi-stage: a shared `base` stage (system packages, PHP extensions,
# Composer binary — everything both environments need identically),
# then `development` (used by docker-compose.yml, bind-mounts the repo
# over what COPY places here) and `production` (used by
# docker-compose.staging.yml, no bind-mount — the image itself is the
# deployable artifact, per ADR-0002's "Production artifacts must be
# immutable"). Neither existing `docker-compose.yml` behavior nor its
# own dev workflow changes — it now builds with `target: development`
# explicitly rather than relying on "last stage wins".
#
# Pinned to 8.5 (not 8.3, despite composer.json's ">=8.3 <8.6" floor):
# the committed composer.lock resolved symfony/* packages that require
# PHP >=8.4.1, so php:8.3.33-cli fails `composer install` in a clean
# container even though the host workstation (PHP 8.5.6) never surfaced
# this. 8.5.10 satisfies both the lock file and composer.json's range.
FROM php:8.5.10-cli AS base

# ADR-0008: SESSION_DRIVER=redis requires the `redis` PECL extension —
# PhpRedisConnector (Laravel's default REDIS_CLIENT) needs the native
# `Redis` class, which does not exist without it. $PHPIZE_DEPS (defined
# by the official php image) provides the compiler toolchain pecl needs;
# it is purged afterward since nothing else in this image needs it.
#
# AETS-009 §19 (Compliance Pack export): the `zip` extension provides
# `ZipArchive`, which `CompliancePackBuilder` needs to bundle several
# CSV reports into one downloadable archive — not present in the base
# php:8.5-cli image, hence `libzip-dev` (build-time) and the
# `docker-php-ext-install ... zip` below.
#
# `gd` is a hard `composer.json` platform requirement of
# `phpoffice/phpspreadsheet` (Import & Export: XLSX bank statement
# import, XLSX report export) — hence `libpng-dev`/`libjpeg-dev`
# (build-time) and `docker-php-ext-install ... gd` below.
#
# `qpdf` (a real system binary, not a Composer package): AETS-008
# §12.10's Maybank PDF import needs it because `smalot/pdfparser`
# (composer.json) has *no* PDF-decryption support of its own at
# all — confirmed by reading its source directly. Most real bank
# statement PDFs (Maybank's own included) are encrypted with
# permissions-only restrictions and an *empty* user password — they
# open instantly in any PDF viewer with no password prompt — which
# `qpdf --decrypt` strips transparently with no password argument
# needed ({@see App\Infrastructure\Banking\QpdfDecryptor}). This is
# the one system-binary dependency in this image; every other PDF
# concern (Dompdf output, text extraction) stays pure-PHP.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libonig-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        unzip \
        curl \
        qpdf \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_pgsql mbstring pcntl posix zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
# `--no-reload`: without it, Laravel's ServeCommand spawns the actual
# request-serving `php -S` subprocess through an environment allowlist
# (Illuminate\Foundation\Console\ServeCommand::$passthroughVariables)
# that silently strips any variable not on it — including every
# docker-compose-injected DB_HOST/REDIS_HOST/etc. this container relies
# on for service discovery. The stripped subprocess then falls back to
# config's own `127.0.0.1` defaults, which cannot reach the `postgres`/
# `redis` containers, surfacing as a "Connection refused" deep inside
# whatever feature first touched a stripped connection (here: Sanctum's
# SESSION_DRIVER=redis). `--no-reload` passes the full environment
# through unchanged; the traded-off `.env`-change auto-restart is not
# needed in a container that gets restarted directly for such changes.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

# Development-only stage. Not built, tagged, or scanned for production
# use — docker-compose.yml bind-mounts the real repo over this COPY,
# so what actually runs is the host's own working tree, not this
# layer's snapshot; `composer install` here (dev deps included) only
# primes the `api_vendor` named volume for the first boot.
FROM base AS development

COPY . .

RUN composer install --no-interaction --no-progress --prefer-dist

# Production stage — used by docker-compose.staging.yml only. No
# bind-mount: this image *is* the deployable artifact (ADR-0002,
# "Production artifacts must be immutable and promoted unchanged
# across environments"). `--no-dev --optimize-autoloader`: no test/
# lint tooling shipped, class map generated once at build time rather
# than resolved via composer's dev autoloader on every request.
FROM base AS production

COPY . .

RUN composer install --no-interaction --no-progress --prefer-dist \
        --no-dev --optimize-autoloader
