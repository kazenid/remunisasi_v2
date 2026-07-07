# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: Build assets
# ─────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS node_builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
RUN npm run build

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: Nginx runtime
# ─────────────────────────────────────────────────────────────────────────────
FROM nginx:1.25-alpine

# Copy custom Nginx configuration
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Copy build assets
COPY --from=node_builder /app/public /var/www/public
