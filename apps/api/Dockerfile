# Development-only image. Not built, tagged, or scanned for production use.
#
# Pinned to 8.5 (not 8.3, despite composer.json's ">=8.3 <8.6" floor):
# the committed composer.lock resolved symfony/* packages that require
# PHP >=8.4.1, so php:8.3.33-cli fails `composer install` in a clean
# container even though the host workstation (PHP 8.5.6) never surfaced
# this. 8.5.10 satisfies both the lock file and composer.json's range.
FROM php:8.5.10-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libonig-dev \
        unzip \
        curl \
    && docker-php-ext-install pdo_pgsql mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --no-progress --prefer-dist

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
