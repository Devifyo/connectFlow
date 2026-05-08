FROM php:8.4-fpm-alpine

# 1. System Dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    libzip-dev \
    unzip \
    shadow \
    nodejs \
    npm

# 2. PHP Extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip

# 3. Install Xdebug for debugging
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

# 3. PHP-FPM Config
RUN sed -i 's|listen = 127.0.0.1:9000|listen = 0.0.0.0:9000|' \
    /usr/local/etc/php-fpm.d/www.conf

# 4. Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. Application Root
WORKDIR /var/www/html

# 6. Copy PHP Config
COPY ./php/local.ini /usr/local/etc/php/conf.d/local.ini

# 7. Copy App Code
COPY . .

# 8. Laravel Directories & Permissions
RUN mkdir -p \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    bootstrap/cache \
    /tmp

RUN chmod -R 777 storage bootstrap/cache /tmp && \
    chown -R www-data:www-data /tmp

# 9. Run as root (permissions managed via volume mounts)

