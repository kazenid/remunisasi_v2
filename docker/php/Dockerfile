# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: Node builder — install all deps & build frontend assets
# ─────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS node_builder

WORKDIR /app

# Copy package files for better layer caching
COPY package.json package-lock.json ./

# Install ALL node deps (including devDependencies needed for Vite build)
RUN npm ci

# Copy source files needed for build
COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/

# Build frontend assets
RUN npm run build

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: PHP — runtime image
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    libpq-dev \
    zip \
    unzip \
    oniguruma-dev

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    opcache

# Configure OPcache for production
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=256"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.revalidate_freq=0"; \
    echo "opcache.save_comments=1"; \
    } >> /usr/local/etc/php/conf.d/opcache.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy composer files first (layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev, optimized)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application source
COPY . .

# Copy built frontend assets from node_builder stage
COPY --from=node_builder /app/public/build ./public/build

# Finalize composer (run post-install scripts, dump autoload)
RUN composer dump-autoload --optimize

# Set correct permissions
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# Custom PHP-FPM config
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

EXPOSE 9000

CMD ["php-fpm"]
