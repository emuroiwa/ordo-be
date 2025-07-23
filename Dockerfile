# Multi-stage build for Laravel backend
FROM php:8.3-fpm-alpine AS base

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    sqlite-dev \
    postgresql-dev \
    oniguruma-dev \
    libzip-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    supervisor \
    nginx \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_sqlite \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create www-data user for Laravel
RUN deluser --remove-home www-data 2>/dev/null || true \
    && delgroup www-data 2>/dev/null || true \
    && addgroup -g 1000 -S www-data \
    && adduser -u 1000 -S www-data -G www-data

#=================================
# Development stage
#=================================
FROM base AS development

# Install development dependencies
RUN apk add --no-cache \
    git \
    nodejs \
    npm

# Install Xdebug for development
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS linux-headers

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (including dev dependencies)
RUN composer install --no-scripts --no-autoloader

# Copy application code
COPY . .

# Generate autoloader and run Laravel setup
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

#=================================
# Production stage
#=================================
FROM base AS production

# Install production PHP configuration
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm-prod.conf /usr/local/etc/php-fpm.d/www.conf

# Copy composer files
COPY composer.json composer.lock ./

# Install PHP dependencies (production only)
RUN composer install --no-dev --no-scripts --no-autoloader --optimize-autoloader

# Copy application code
COPY . .

# Optimize Laravel for production
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Copy Nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy Supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create necessary directories
RUN mkdir -p /var/log/supervisor \
    && mkdir -p /run/nginx

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

#=================================
# Queue worker stage
#=================================
FROM production AS queue-worker

# Override CMD to run queue worker
CMD ["php", "artisan", "queue:work", "--sleep=3", "--tries=3", "--max-time=3600", "--memory=512"]

#=================================
# Scheduler stage
#=================================
FROM production AS scheduler

# Install cron
RUN apk add --no-cache dcron

# Copy crontab
COPY docker/cron/laravel-cron /etc/crontabs/www-data

# Override CMD to run cron
CMD ["crond", "-f", "-l", "2"]