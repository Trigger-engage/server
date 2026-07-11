FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY public public
COPY vite.config.js ./
RUN npm run build

FROM php:8.4-fpm-alpine AS app
WORKDIR /var/www/html
RUN apk add --no-cache icu-dev libzip-dev postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install intl opcache pcntl pdo_mysql pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS
COPY . .
COPY --from=vendor /app/vendor vendor
COPY --from=frontend /app/public/build public/build
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=frontend /app/public/build /var/www/html/public/build
EXPOSE 80
