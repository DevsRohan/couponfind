FROM php:8.3-fpm-alpine

# System deps + PHP extensions required by the app
RUN apk add --no-cache \
        git unzip icu-dev oniguruma-dev curl \
    && docker-php-ext-install pdo pdo_mysql intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/backend

COPY backend /var/www/backend

# The app has no third-party Composer dependencies — just generate the
# optimized PSR-4 autoloader (no lock file needed).
RUN composer dump-autoload --optimize --no-interaction

# Opcache tuning for production-like behavior
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.max_accelerated_files=10000"; \
        echo "opcache.validate_timestamps=1"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

EXPOSE 9000
CMD ["php-fpm"]
