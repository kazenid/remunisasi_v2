# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: Ziggy — menyediakan vendor/tightenco/ziggy untuk build Vite
# (identik dengan stage ziggy di docker/php/Dockerfile agar hash aset sama)
# ─────────────────────────────────────────────────────────────────────────────
FROM composer:2 AS ziggy
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --ignore-platform-reqs

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: Build assets
# ─────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS node_builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=ziggy /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
RUN rm -f public/hot
RUN npm run build

# ─────────────────────────────────────────────────────────────────────────────
# Stage 3: Nginx runtime
# ─────────────────────────────────────────────────────────────────────────────
FROM nginx:1.25-alpine

# Copy custom Nginx configuration
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy build assets
COPY --from=node_builder /app/public /var/www/public
