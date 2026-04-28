# syntax=docker/dockerfile:1.7
# ==========================================
# 1. 全域變數定義區
# ==========================================
ARG TIME_ZONE=Asia/Taipei
ARG PHP_VERSION=8.2.30
ARG ALPINE_VERSION=3.22
ARG COMPOSER_VERSION=2.9.7
ARG PHPREDIS_VERSION=6.3.0
ARG PHP_IPE_VERSION=2.10.18
ARG COMPOSER_CACHE_DIR=/tmp/composer-cache

# ==========================================
# 1.1 Composer binary alias
# ==========================================
FROM composer:${COMPOSER_VERSION} AS composer-bin

# ==========================================
# 1.2 IPE binary alias
# ==========================================
FROM mlocati/php-extension-installer:${PHP_IPE_VERSION} AS ipe-bin

# ==========================================
# 2. Base Stage (PHP runtime + extensions via IPE 用大神改好的直接裝比較快)
#    https://github.com/mlocati/docker-php-extension-installer
# ==========================================
FROM php:${PHP_VERSION}-fpm-alpine${ALPINE_VERSION} AS base

ARG TIME_ZONE
ARG PHPREDIS_VERSION
ARG PHP_IPE_VERSION

ENV TZ=${TIME_ZONE}

WORKDIR /app

# IPE 自動處理 build deps + strip + cache
COPY --from=ipe-bin /usr/bin/install-php-extensions /usr/local/bin/

RUN apk --no-cache add \
    libzip icu-libs icu-data-full gmp tzdata \
    libpng libavif libjpeg-turbo libwebp freetype && \
    install-php-extensions \
    gd pdo_mysql intl opcache zip gmp bcmath pcntl \
    redis-${PHPREDIS_VERSION} && \
    ln -sf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone && \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    docker-php-source delete && \
    rm /usr/local/bin/install-php-extensions && \
    rm -rf /tmp/* /var/cache/apk/*

# 客製化 ini / fpm 配置 (prefix 999-檔名 放最後)
# COPY php.ini $PHP_INI_DIR/conf.d/999-custom.ini
# COPY php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# ==========================================
# 3. Vendor (含 dev，給 testing 用)
# ==========================================
FROM base AS vendor-test
ARG COMPOSER_CACHE_DIR

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./

# 用 Docker BuildKit 的 Cache Mount
# 掛一個持久的資料夾不在鏡像內 減少體積在 host 緩存等等用的意思
RUN --mount=type=cache,target=${COMPOSER_CACHE_DIR},sharing=locked \
    COMPOSER_HOME=${COMPOSER_CACHE_DIR} \
    composer install \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# ==========================================
# 4. Vendor-Prod (--no-dev + optimize-autoloader 一次做完)
# ==========================================
FROM base AS vendor-prod
ARG COMPOSER_CACHE_DIR

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources/views ./resources/views
COPY routes ./routes
COPY artisan ./

RUN --mount=type=cache,target=${COMPOSER_CACHE_DIR},sharing=locked \
    COMPOSER_HOME=${COMPOSER_CACHE_DIR} \
    composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

# ==========================================
# 5. Testing Stage (跑 phpunit)
# ==========================================
FROM base AS testing

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY --from=vendor-test /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --optimize --no-scripts

CMD ["vendor/bin/phpunit"]

# ==========================================
# 6. Production Stage (最小化)
# ==========================================
FROM base AS production

# vendor-prod 已含 vendor + 全部執行期需要的 PHP 程式碼
COPY --from=vendor-prod --chown=www-data:www-data /app /app

RUN mkdir -p storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -v >/dev/null 2>&1 || exit 1

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
