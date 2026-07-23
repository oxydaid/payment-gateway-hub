# Stage 1: Frontend Asset Compilation
FROM node:22-alpine AS frontend-builder
WORKDIR /app

# Enable pnpm
RUN npm install -g pnpm

# Copy package lock and configurations
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

# Copy source code and build
COPY . .
RUN pnpm build

# Stage 2: PHP FPM Application
FROM php:8.4-fpm-alpine AS php-app
WORKDIR /var/www

# Install system dependencies and php extensions
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    git \
    oniguruma-dev \
    mariadb-client

RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    xml \
    gd \
    zip \
    opcache

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy configuration files
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/custom.ini

# Copy project files
COPY . .

# Copy compiled frontend assets from Stage 1
COPY --from=frontend-builder /app/public/build ./public/build

# Install php dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions for storage and bootstrap/cache
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
