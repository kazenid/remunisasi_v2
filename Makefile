# ──────────────────────────────────────────────────────────────────────────────
# Makefile — TUKIMAN (remunisasi_v2)
#
# Jalankan `make` atau `make help` untuk melihat daftar target.
# Kebutuhan: GNU Make. Linux/macOS: bawaan. Windows: WSL, Git Bash, atau
# `choco install make` / `scoop install make`.
#
# Catatan keamanan: tidak ada target yang menghapus volume (`down -v`) atau
# mereset database. Jangan menambahkannya tanpa konfirmasi eksplisit.
# ──────────────────────────────────────────────────────────────────────────────

.DEFAULT_GOAL := help

# Perintah Docker Compose untuk stack pengembangan dan produksi.
DC_DEV  = docker compose -f docker-compose.dev.yml
DC_PROD = docker compose -f docker-compose.yml

# Parameter opsional (diisi lewat baris perintah):
#   CMD     — argumen artisan untuk dev-artisan / prod-artisan
#   SERVICE — nama service untuk dev-logs / prod-logs (kosong = semua)
CMD     ?=
SERVICE ?=

.PHONY: help \
	dev-up dev-down dev-restart dev-logs dev-shell dev-artisan dev-migrate \
	dev-test dev-test-redis dev-nginx-reload \
	prod-build prod-up prod-down prod-logs prod-ps prod-restart prod-fix-perms \
	prod-key prod-shell prod-artisan prod-migrate prod-cache prod-optimize-clear \
	install build test

help: ## Tampilkan daftar target ini
	@awk 'BEGIN {FS = ":.*##"; printf "\nPemakaian: make \033[36m<target>\033[0m\n"} \
		/^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } \
		/^[a-zA-Z0-9_-]+:.*##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)
	@echo ""

##@ Pengembangan (Docker) — docker-compose.dev.yml: app, nginx (:8085), redis (:6380), mysql (:3307), vite (:5173)

dev-up: ## Build dan jalankan stack dev di latar belakang
	$(DC_DEV) up -d --build

dev-down: ## Hentikan stack dev (volume TIDAK dihapus)
	$(DC_DEV) down

dev-restart: ## Restart semua container dev
	$(DC_DEV) restart

dev-logs: ## Ikuti log container dev (opsional: SERVICE=app)
	$(DC_DEV) logs -f --tail=100 $(SERVICE)

dev-shell: ## Masuk ke shell (bash) container app dev
	$(DC_DEV) exec app bash

dev-artisan: ## Jalankan artisan di container dev, mis. make dev-artisan CMD="migrate:status"
	@test -n "$(CMD)" || { echo 'Isi CMD, contoh: make dev-artisan CMD="migrate:status"'; exit 1; }
	$(DC_DEV) exec app php artisan $(CMD)

dev-migrate: ## Jalankan migrasi database di container dev
	$(DC_DEV) exec -T app php artisan migrate

# Catatan dev-test:
# - DB_DATABASE=:memory: dipaksa lewat `exec -e`. Tanpa ini, env compose
#   (DB_DATABASE=remunisasi_v2) menimpa phpunit.xml dan tes membuat berkas SQLite
#   liar bernama `remunisasi_v2` di root repo.
# - Ekstensi GD di image dev dibangun tanpa dukungan JPEG, sehingga tes yang memakai
#   UploadedFile::fake()->image('x.jpg') gagal (imagejpeg tidak tersedia) di container,
#   tetapi lulus di host. Itu bukan regresi notifikasi.
dev-test: ## Jalankan seluruh tes di container dev (SQLite in-memory)
	$(DC_DEV) exec -T -e DB_DATABASE=:memory: app php artisan test

dev-test-redis: ## Jalankan tes integrasi SSE dengan Redis nyata (REDIS_INTEGRATION=1)
	$(DC_DEV) exec -T -e REDIS_INTEGRATION=1 -e DB_DATABASE=:memory: app php artisan test --filter=NotificationStreamTest

# Konfigurasi nginx dev di-mount sebagai berkas tunggal. Bila perubahan tidak terbaca
# setelah reload, pakai: docker compose -f docker-compose.dev.yml restart nginx
dev-nginx-reload: ## Uji konfigurasi lalu reload nginx dev
	$(DC_DEV) exec nginx nginx -t
	$(DC_DEV) exec nginx nginx -s reload

##@ Produksi (Docker) — docker-compose.yml: app, nginx (:APP_PORT, default 8000), queue; MySQL & Redis di host

prod-build: ## Build image produksi (app, nginx, queue)
	$(DC_PROD) build

prod-up: ## Build dan jalankan stack produksi di latar belakang
	$(DC_PROD) up -d --build

prod-down: ## Hentikan stack produksi (volume sakai_storage TIDAK dihapus)
	$(DC_PROD) down

prod-logs: ## Ikuti log produksi (opsional: SERVICE=app)
	$(DC_PROD) logs -f --tail=100 $(SERVICE)

prod-ps: ## Tampilkan status container produksi
	$(DC_PROD) ps

prod-restart: ## Restart semua container produksi
	$(DC_PROD) restart

# Cukup sekali bila volume storage lama pernah ditulis sebagai root
# (service queue kini berjalan sebagai www-data).
prod-fix-perms: ## Perbaiki kepemilikan storage ke www-data (sekali, untuk volume lama)
	$(DC_PROD) exec -T -u root app chown -R www-data:www-data /var/www/storage

# .env TIDAK masuk ke image: tempel hasilnya ke .env di host, bukan di container.
prod-key: ## Cetak APP_KEY baru (tidak menulis berkas); tempel ke .env host
	$(DC_PROD) run --rm app php artisan key:generate --show

prod-shell: ## Masuk ke shell (bash) container app produksi sebagai www-data
	$(DC_PROD) exec -u www-data app bash

prod-artisan: ## Jalankan artisan sebagai www-data, mis. make prod-artisan CMD="app:set-admin 1290040"
	@test -n "$(CMD)" || { echo 'Isi CMD, contoh: make prod-artisan CMD="migrate:status"'; exit 1; }
	$(DC_PROD) exec -u www-data app php artisan $(CMD)

prod-migrate: ## Jalankan migrasi produksi (--force) sebagai www-data
	$(DC_PROD) exec -T -u www-data app php artisan migrate --force

# Jalankan ulang setelah mengubah .env atau rute (config dan route di-cache).
prod-cache: ## Cache config, route, dan view
	$(DC_PROD) exec -T -u www-data app php artisan config:cache
	$(DC_PROD) exec -T -u www-data app php artisan route:cache
	$(DC_PROD) exec -T -u www-data app php artisan view:cache

prod-optimize-clear: ## Hapus seluruh cache framework (config, route, view, dll.)
	$(DC_PROD) exec -T -u www-data app php artisan optimize:clear

##@ Lokal (tanpa Docker) — butuh PHP, Composer, Node.js dan npm di mesin Anda

install: ## Pasang dependensi: composer install + npm ci
	composer install
	npm ci

build: ## Build aset frontend (Vite) ke public/build
	npm run build

test: ## Jalankan tes (php artisan test)
	php artisan test
