# 🚀 Sakai Laravel

<div align="center">

![Sakai Laravel Banner](https://primefaces.org/cdn/primevue/images/sakai-vue/sakai-vue-preview.jpg)

**Admin template berbasis Laravel + Vue 3 + Inertia.js + TailwindCSS 4 + PrimeVue 4**

[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Vue](https://img.shields.io/badge/Vue-3.x-42B883?style=flat-square&logo=vue.js)](https://vuejs.org)
[![Inertia](https://img.shields.io/badge/Inertia.js-2.x-7C3AED?style=flat-square)](https://inertiajs.com)
[![TailwindCSS](https://img.shields.io/badge/TailwindCSS-4.x-06B6D4?style=flat-square&logo=tailwindcss)](https://tailwindcss.com)
[![PrimeVue](https://img.shields.io/badge/PrimeVue-4.x-41B883?style=flat-square)](https://primevue.org)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php)](https://www.php.net)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql)](https://www.postgresql.org)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE.md)

</div>

---

## 📋 Daftar Isi

- [Tentang Proyek](#-tentang-proyek)
- [Stack Teknologi](#-stack-teknologi)
- [Struktur Direktori](#-struktur-direktori)
- [Syarat Sistem](#-syarat-sistem)
- [Instalasi Lokal](#-instalasi-lokal)
- [Konfigurasi Environment](#-konfigurasi-environment)
- [Deploy dengan Docker](#-deploy-dengan-docker)
- [Deploy dengan Podman](#-deploy-dengan-podman)
- [Cara Kerja Inertia.js](#-cara-kerja-inertiajs)
- [Menambah Halaman Baru](#-menambah-halaman-baru)
- [Dark Mode & Theme](#-dark-mode--theme)
- [Akun Demo](#-akun-demo)
- [Troubleshooting](#-troubleshooting)

---

## 📖 Tentang Proyek

**Sakai Laravel** adalah implementasi lengkap dari template [Sakai Vue](https://github.com/primefaces/sakai-vue) ke dalam ekosistem Laravel full-stack menggunakan **Inertia.js** sebagai jembatan antara Laravel (backend) dan Vue 3 (frontend) — tanpa REST API terpisah.

### ✨ Fitur Utama

- 🎨 **Theme Configurator** — 18 warna primer, 8 surface palette, 3 preset (Aura/Lara/Nora)
- 🌙 **Dark Mode** — Toggle dengan smooth View Transition API
- 📱 **Responsive** — Static & Overlay sidebar mode
- 🔐 **Authentication** — Login & Register dengan session Laravel
- 📦 **CRUD Demo** — Manajemen produk lengkap dengan validasi server
- 🗂️ **15 UIKit Pages** — Showcase semua komponen PrimeVue
- 🐘 **PostgreSQL** — Database production-grade dengan performa tinggi
- 🐳 **Docker Ready** — Multi-stage build untuk production, Vite HMR untuk development
- 🦭 **Podman Compatible** — Alternatif Docker tanpa daemon

---

## 🛠️ Stack Teknologi

| Teknologi | Versi | Fungsi |
|-----------|-------|--------|
| **Laravel** | 13.x | Backend framework, routing, auth, ORM |
| **Inertia.js** | 2.x | Bridge Laravel ↔ Vue (tanpa REST API) |
| **Vue 3** | 3.4+ | Frontend reaktif (Composition API) |
| **TailwindCSS** | 4.x | Utility-first CSS (CSS-first config) |
| **PrimeVue** | 4.x | UI component library lengkap |
| **Vite** | 5.x | Build tool super cepat + HMR |
| **Ziggy** | 2.x | Named routes Laravel di JavaScript |
| **PHP** | 8.4 | Runtime PHP terbaru |
| **PostgreSQL** | 16 | Database relasional production-grade |
| **Redis** | 7 | Cache & queue driver |
| **Node.js** | 22 | Build frontend assets |

---

## 📁 Struktur Direktori

```
sakai-laravel/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── LoginController.php      # Handle login/logout
│   │   │   │   └── RegisterController.php   # Handle registrasi
│   │   │   ├── DashboardController.php      # Dashboard + stats
│   │   │   └── ProductController.php        # CRUD produk
│   │   └── Middleware/
│   │       └── HandleInertiaRequests.php    # Share auth/flash ke semua page
│   └── Models/
│       ├── User.php
│       └── Product.php
│
├── resources/
│   ├── js/
│   │   ├── app.js                  # Entry point Inertia + PrimeVue
│   │   ├── layout/                 # Layout components (ported dari sakai-vue)
│   │   │   ├── composables/
│   │   │   │   └── layout.js       # State management sidebar/theme
│   │   │   ├── AppTopbar.vue
│   │   │   ├── AppSidebar.vue
│   │   │   ├── AppMenu.vue         # Navigation menu (Ziggy routes)
│   │   │   ├── AppMenuItem.vue     # Recursive menu item
│   │   │   ├── AppFooter.vue
│   │   │   └── AppConfigurator.vue # Theme picker
│   │   ├── Layouts/
│   │   │   ├── AppLayout.vue       # Layout admin (dengan sidebar/topbar)
│   │   │   └── GuestLayout.vue     # Layout auth (halaman login/register)
│   │   ├── Components/
│   │   │   ├── FloatingConfigurator.vue
│   │   │   └── dashboard/          # 5 widget dashboard
│   │   └── Pages/                  # Semua halaman Inertia
│   │       ├── Dashboard.vue
│   │       ├── Auth/
│   │       │   ├── Login.vue
│   │       │   ├── Register.vue
│   │       │   ├── Access.vue
│   │       │   └── Error.vue
│   │       ├── UIKit/              # 15 halaman UIKit
│   │       └── Pages/
│   │           ├── Crud.vue        # CRUD produk
│   │           ├── Empty.vue
│   │           └── NotFound.vue
│   ├── css/
│   │   └── app.css                 # TailwindCSS 4 + layout styles
│   └── views/
│       └── app.blade.php           # Root Blade (Inertia mount point)
│
├── routes/
│   └── web.php                     # Semua routes
│
├── database/
│   ├── migrations/
│   └── seeders/
│       └── DatabaseSeeder.php      # 15 produk demo + admin user
│
├── docker/
│   ├── nginx/
│   │   └── default.conf            # Nginx config
│   └── php/
│       ├── Dockerfile              # Production: PHP 8.4 multi-stage build
│       ├── Dockerfile.dev          # Development: PHP 8.4 + Node 22 + npm
│       └── php-fpm.conf            # PHP-FPM pool config
│
├── docker-compose.yml              # Production: app + nginx + postgres + redis + queue
├── docker-compose.dev.yml          # Development: dengan Vite HMR service
├── vite.config.js                  # Vite + TailwindCSS + PrimeVue (Docker-ready)
└── .env.example                    # Template environment variables
```

---

## ✅ Syarat Sistem

### Untuk Local Development (tanpa Docker)
| Software | Versi Minimum |
|----------|--------------| 
| PHP | 8.4+ |
| PHP Extensions | `pdo_pgsql`, `pgsql`, `mbstring`, `bcmath`, `gd`, `pcntl` |
| Composer | 2.x |
| Node.js | 22+ |
| npm | 10+ |
| PostgreSQL | 14+ |

### Untuk Docker / Podman
| Software | Versi | Platform |
|----------|-------|---------|
| Docker Desktop | 4.x+ | Windows, Mac, Linux |
| Docker Engine | 24+ | Linux |
| Podman Desktop | 1.x+ | Windows, Mac |
| Podman + podman-compose | 4.x+ | Linux |

---

## 🚀 Instalasi Lokal

### 1. Clone repositori

```bash
git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel
```

### 2. Install dependensi PHP

```bash
composer install
```

### 3. Konfigurasi environment

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Setup database PostgreSQL

Pastikan PostgreSQL sudah terinstall dan berjalan, lalu buat user dan database:

```bash
# Masuk ke PostgreSQL sebagai superuser
psql -U postgres

# Buat user, database, dan assign privilege
CREATE USER sakai WITH PASSWORD 'secret';
CREATE DATABASE sakai_laravel OWNER sakai;
GRANT ALL PRIVILEGES ON DATABASE sakai_laravel TO sakai;
\q
```

Pastikan `.env` berisi:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=sakai_laravel
DB_USERNAME=sakai
DB_PASSWORD=secret
```

### 5. Jalankan migrasi dan seeder

```bash
php artisan migrate --seed
```

### 6. Install dependensi Node

```bash
npm install
```

### 7. Jalankan server development

Buka **2 terminal** secara bersamaan:

```bash
# Terminal 1 — Laravel backend
php artisan serve

# Terminal 2 — Vite (HMR/hot reload)
npm run dev
```

Buka browser: **http://localhost:8000**

---

## ⚙️ Konfigurasi Environment

Salin `.env.example` menjadi `.env` dan sesuaikan nilainya:

```env
# ─── Aplikasi ─────────────────────────────────────────────────────────────
APP_NAME="Sakai Laravel"
APP_ENV=local           # Ganti ke "production" untuk prod
APP_DEBUG=true          # Matikan (false) di production!
APP_URL=http://localhost:8000

# ─── Database (PostgreSQL) ────────────────────────────────────────────────
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1       # Gunakan "postgres" jika pakai Docker
DB_PORT=5432
DB_DATABASE=sakai_laravel
DB_USERNAME=sakai
DB_PASSWORD=secret

# ─── Session & Cache ──────────────────────────────────────────────────────
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# ─── Redis (opsional, untuk cache & queue lebih cepat) ────────────────────
REDIS_HOST=127.0.0.1    # Gunakan "redis" jika pakai Docker
REDIS_PORT=6379
```

> **Tips:** Untuk Docker, ubah `DB_HOST=postgres` dan `REDIS_HOST=redis` sesuai nama service di `docker-compose.yml`.

---

## 🐳 Deploy dengan Docker

Project ini menyediakan dua mode Docker:

| Mode | File | Kegunaan |
|------|------|----------|
| **Production** | `docker-compose.yml` | Deploy siap pakai, frontend sudah dibuild |
| **Development** | `docker-compose.dev.yml` | Dengan Vite HMR, source di-mount sebagai volume |

### Arsitektur Docker

```
┌─────────────────────────────────────────────────────┐
│                   Docker Network                     │
│                                                      │
│  ┌──────────┐    ┌──────────┐    ┌───────────────┐  │
│  │  nginx   │───▶│   app    │───▶│   postgres    │  │
│  │ :80→8000 │    │ PHP 8.4  │    │ :5432         │  │
│  └──────────┘    │ FPM:9000 │    └───────────────┘  │
│                  └──────────┘                        │
│  ┌──────────┐    ┌──────────┐    ┌───────────────┐  │
│  │  vite*   │    │  queue   │───▶│    redis      │  │
│  │:5173(dev)│    │ worker   │    │ :6379         │  │
│  └──────────┘    └──────────┘    └───────────────┘  │
│  (* hanya di docker-compose.dev.yml)                 │
└─────────────────────────────────────────────────────┘
```

### Dockerfile Build Strategy

**Production** (`docker/php/Dockerfile`) — **Multi-stage build:**
- **Stage 1 (node_builder)**: Node 22 menginstall semua npm deps & build aset Vite
- **Stage 2 (php runtime)**: PHP 8.4-FPM Alpine, copy hasil build dari stage 1
- Hasil: image ringan tanpa Node/npm di production

**Development** (`docker/php/Dockerfile.dev`) — **Single stage:**
- PHP 8.4-FPM Alpine + Node 22 + npm dalam satu image
- Source code di-mount sebagai volume (tidak di-copy ke image)
- Vite berjalan sebagai service terpisah untuk HMR

---

### 🖥️ Windows

#### Persiapan
1. Install **Docker Desktop** dari https://www.docker.com/products/docker-desktop/
2. Aktifkan **WSL2** saat diminta (direkomendasikan untuk performa terbaik)
3. Buka **PowerShell** atau **Windows Terminal**

#### Mode Production

```powershell
# Clone project
git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel

# Salin env
Copy-Item .env.example .env
```

Edit `.env`, ubah konfigurasi database untuk Docker:

```env
DB_CONNECTION=pgsql
DB_HOST=postgres        # ← nama service Docker, BUKAN localhost
DB_PORT=5432
DB_DATABASE=sakai_laravel
DB_USERNAME=sakai
DB_PASSWORD=secret
REDIS_HOST=redis        # ← nama service Docker
```

```powershell
# Build dan jalankan semua service (pertama kali ~5-10 menit)
docker compose up -d --build

# Cek semua container berjalan
docker compose ps

# Generate app key (hanya pertama kali)
docker compose exec app php artisan key:generate

# Jalankan migrasi + seeder (hanya pertama kali)
docker compose exec app php artisan migrate --seed
```

Buka browser: **http://localhost:8000** ✅

#### Mode Development (dengan Vite HMR)

```powershell
# Salin env (jika belum)
Copy-Item .env.example .env
# Edit .env: DB_HOST=postgres, REDIS_HOST=redis

# Jalankan stack development
docker compose -f docker-compose.dev.yml up -d --build

# Cek semua service running
docker compose -f docker-compose.dev.yml ps

# Pertama kali: install deps & migrate
docker compose -f docker-compose.dev.yml exec app composer install
docker compose -f docker-compose.dev.yml exec app php artisan key:generate
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
docker compose -f docker-compose.dev.yml exec app npm install
```

Akses:
- App: **http://localhost:8000**
- Vite HMR: **http://localhost:5173**

#### Perintah Berguna (Windows)

```powershell
# Lihat log semua service secara realtime
docker compose logs -f

# Lihat log service tertentu
docker compose logs -f app
docker compose logs -f postgres

# Masuk ke container PHP
docker compose exec app bash

# Masuk ke PostgreSQL
docker compose exec postgres psql -U sakai -d sakai_laravel

# Jalankan artisan command
docker compose exec app php artisan migrate:status
docker compose exec app php artisan optimize:clear

# Stop semua service
docker compose down

# Stop & hapus semua volume (RESET TOTAL — data hilang!)
docker compose down -v

# Rebuild image dari awal
docker compose up -d --build --force-recreate
```

---

### 🍎 Mac / 🐧 Linux

#### Persiapan (Mac)

```bash
# Opsi 1 — Docker Desktop (GUI)
# Download dari: https://www.docker.com/products/docker-desktop/

# Opsi 2 — OrbStack (lebih ringan, direkomendasikan untuk Mac)
# Download dari: https://orbstack.dev
brew install orbstack
```

#### Persiapan (Linux)

```bash
# Install Docker Engine (cara resmi, support Ubuntu/Debian/Fedora/RHEL)
curl -fsSL https://get.docker.com | sh

# Tambahkan user ke group docker (tidak perlu sudo tiap perintah)
sudo usermod -aG docker $USER
newgrp docker

# Install Docker Compose plugin
sudo apt install docker-compose-plugin   # Ubuntu/Debian
sudo dnf install docker-compose-plugin   # Fedora/RHEL

# Verifikasi instalasi
docker --version
docker compose version
```

#### Mode Production

```bash
# Clone project
git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel

# Salin dan edit env
cp .env.example .env
nano .env   # atau: code .env / vim .env

# Ubah nilai penting:
# DB_HOST=postgres
# REDIS_HOST=redis
# APP_ENV=production
# APP_DEBUG=false
# APP_URL=http://your-domain.com

# Build dan jalankan (pertama kali ~5-10 menit)
docker compose up -d --build

# Cek status container
docker compose ps

# Setup awal (hanya sekali)
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# Optimalkan untuk production
docker compose exec app php artisan optimize
```

Buka browser: **http://localhost:8000** ✅

#### Mode Development (dengan Vite HMR)

```bash
# Salin env
cp .env.example .env

# Edit env untuk Docker
sed -i 's/DB_HOST=127.0.0.1/DB_HOST=postgres/' .env
sed -i 's/REDIS_HOST=127.0.0.1/REDIS_HOST=redis/' .env

# Jalankan stack development
docker compose -f docker-compose.dev.yml up -d --build

# Cek status
docker compose -f docker-compose.dev.yml ps

# Pertama kali: install deps, migrate, seed
docker compose -f docker-compose.dev.yml exec app composer install
docker compose -f docker-compose.dev.yml exec app php artisan key:generate
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
docker compose -f docker-compose.dev.yml exec app npm install
```

Akses:
- App: **http://localhost:8000**
- Vite HMR: **http://localhost:5173**

> Vite HMR (hot-reload) akan otomatis reload browser saat Anda mengubah file Vue/CSS. Node modules di-cache sebagai Docker named volume sehingga tidak perlu install ulang tiap restart.

#### Perintah Berguna (Mac/Linux)

```bash
# ─── Monitoring ──────────────────────────────────────────────────────────
docker compose logs -f              # Semua log (realtime)
docker compose logs -f app          # Log PHP-FPM
docker compose logs -f postgres     # Log PostgreSQL
docker compose logs -f nginx        # Log Nginx
docker compose ps                   # Status semua container
docker stats                        # Resource usage realtime

# ─── Container Access ────────────────────────────────────────────────────
docker compose exec app bash                              # Shell ke PHP container
docker compose exec postgres psql -U sakai -d sakai_laravel  # PostgreSQL REPL

# ─── Laravel Artisan ─────────────────────────────────────────────────────
docker compose exec app php artisan migrate               # Jalankan migrasi baru
docker compose exec app php artisan migrate:fresh --seed  # Reset + seed ulang
docker compose exec app php artisan migrate:status        # Cek status migrasi
docker compose exec app php artisan optimize:clear        # Clear semua cache
docker compose exec app php artisan optimize              # Build cache (production)
docker compose exec app php artisan queue:restart         # Restart queue workers

# ─── Lifecycle ───────────────────────────────────────────────────────────
docker compose stop                 # Stop container (data tetap ada)
docker compose start                # Start kembali
docker compose down                 # Stop + hapus container
docker compose down -v              # Stop + hapus container + volume (RESET TOTAL)
docker compose up -d --build        # Build ulang + start
```

---

## 🦭 Deploy dengan Podman

Podman adalah alternatif Docker yang **rootless** (lebih aman) dan **tanpa daemon**.

### Windows

```powershell
# Install Podman Desktop: https://podman-desktop.io
# Setelah install, buka Podman Desktop → "Initialize and start"

pip install podman-compose

git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel
Copy-Item .env.example .env
# Edit .env: DB_HOST=postgres, REDIS_HOST=redis

podman-compose -f docker-compose.yml up -d --build
podman exec -it sakai_app php artisan key:generate
podman exec -it sakai_app php artisan migrate --seed
```

### Mac

```bash
brew install podman podman-compose

podman machine init --cpus 2 --memory 4096
podman machine start

cd sakai-laravel
podman-compose up -d --build
podman exec -it sakai_app php artisan key:generate
podman exec -it sakai_app php artisan migrate --seed
```

### Linux

```bash
sudo apt install podman            # Ubuntu/Debian
# atau
sudo dnf install podman            # Fedora/RHEL

pip3 install podman-compose

cd sakai-laravel
podman-compose up -d --build
podman exec -it sakai_app php artisan key:generate
podman exec -it sakai_app php artisan migrate --seed
```

> **Catatan Podman:** Jika ada issue dengan DNS antar container, tambahkan `--network=host` atau gunakan `host.containers.internal` sebagai hostname.

---

## 💡 Cara Kerja Inertia.js

Inertia.js adalah "jembatan" yang menghubungkan Laravel dan Vue tanpa REST API:

```
User klik link
     │
     ▼
Laravel Route (web.php)
     │
     ▼
Controller Method
     │  Inertia::render('Dashboard', ['products' => $data])
     ▼
app.blade.php (hanya dimuat SEKALI)
     │
     ▼
Vue Component (Pages/Dashboard.vue)
     │  menerima $data sebagai props
     ▼
Tampilan ke User (SPA-like navigation)
```

**Yang perlu diingat:**
- Setiap navigasi menggunakan `<Link>` dari `@inertiajs/vue3` (bukan `<a href>`)
- Form menggunakan `useForm()` dari Inertia (bukan axios/fetch)
- Data dari controller dikirim sebagai props Vue
- Layout ditentukan via `defineOptions({ layout: AppLayout })`

---

## 📝 Menambah Halaman Baru

### 1. Buat route di `routes/web.php`

```php
Route::get('/halaman-saya', function () {
    return Inertia::render('Pages/HalamanSaya', [
        'data' => SomeModel::all(),
    ]);
})->name('pages.halaman-saya');
```

### 2. Buat Vue component di `resources/js/Pages/Pages/HalamanSaya.vue`

```vue
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });

defineProps({
    data: Array,
});
</script>

<template>
    <Head title="Halaman Saya" />
    <div class="card">
        <h1>Halaman Saya</h1>
        <!-- gunakan PrimeVue components -->
    </div>
</template>
```

### 3. Tambah menu di `resources/js/layout/AppMenu.vue`

```js
{
    label: 'Halaman Saya',
    icon: 'pi pi-fw pi-file',
    route: 'pages.halaman-saya',
}
```

---

## 🎨 Dark Mode & Theme

### Dark Mode
Dark mode di-toggle via tombol di topbar. Implementasinya menggunakan CSS class `.app-dark` di `<html>` element dan PrimeVue's `darkModeSelector`.

### Theme Configurator
Klik ikon 🎨 (palette) di topbar untuk membuka configurator:
- **Primary Color** — 18 pilihan warna
- **Surface** — 8 pilihan surface palette
- **Preset** — Aura (default), Lara, Nora
- **Menu Mode** — Static (selalu tampil) atau Overlay (di atas konten)

---

## 👤 Akun Demo

Setelah menjalankan `php artisan migrate --seed`:

| Field    | Value              |
|----------|------------------|
| Email    | admin@sakai.dev    |
| Password | password           |

---

## 🔧 Perintah Berguna

```bash
# ─── Development (lokal) ──────────────────────────────────────────────────
php artisan serve                    # Jalankan Laravel dev server
npm run dev                          # Jalankan Vite dengan HMR

# ─── Database ─────────────────────────────────────────────────────────────
php artisan migrate                  # Jalankan migrasi baru
php artisan migrate:fresh --seed     # Reset + seed ulang
php artisan migrate:status           # Cek status semua migrasi
php artisan db:seed                  # Seed tanpa reset schema

# ─── Cache & Optimization ─────────────────────────────────────────────────
php artisan config:clear             # Clear config cache
php artisan route:clear              # Clear route cache
php artisan view:clear               # Clear view cache
php artisan optimize:clear           # Clear semua cache sekaligus
php artisan optimize                 # Build cache (untuk production)

# ─── Production Build ─────────────────────────────────────────────────────
npm run build                        # Build frontend assets (Vite)

# ─── Testing ──────────────────────────────────────────────────────────────
php artisan test                     # Jalankan semua test
php artisan test --filter=NamaTest   # Jalankan test tertentu

# ─── Docker (production) ──────────────────────────────────────────────────
docker compose up -d --build         # Build & start semua service
docker compose exec app php artisan migrate --seed
docker compose exec postgres psql -U sakai -d sakai_laravel
docker compose logs -f               # Tail semua log
docker compose down                  # Stop & remove containers
docker compose down -v               # + hapus volumes (RESET DATA)

# ─── Docker (development) ─────────────────────────────────────────────────
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
docker compose -f docker-compose.dev.yml exec app npm install
docker compose -f docker-compose.dev.yml logs -f
```

---

## 🐛 Troubleshooting

### ❌ Error: "Route [xxx] not defined"
Ziggy route tidak dimuat. Pastikan:
1. `@routes` ada di `resources/views/app.blade.php`
2. Jalankan `php artisan route:clear`
3. Cek nama route di `web.php` cocok dengan pemanggilan di `AppMenu.vue`

### ❌ Halaman putih / blank setelah login
```bash
php artisan config:clear
php artisan view:clear
npm run dev  # pastikan Vite berjalan
```

### ❌ Docker: Port already in use
```bash
# Ubah port di .env
APP_PORT=8001
DB_PORT=5433   # Jika 5432 bentrok dengan PostgreSQL lokal

docker compose down && docker compose up -d
```

### ❌ Permission denied di storage/
```bash
chmod -R 775 storage bootstrap/cache
# atau di Docker:
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### ❌ Docker Dev: "npm: command not found" di container app
```bash
# Pastikan menggunakan Dockerfile.dev, bukan Dockerfile biasa
docker compose -f docker-compose.dev.yml exec app npm --version

# Jika masih error, rebuild image:
docker compose -f docker-compose.dev.yml build --no-cache app
```

### ❌ Docker Dev: Vite HMR tidak bekerja (perubahan tidak reload)
Pastikan `vite.config.js` sudah punya konfigurasi:
```js
server: {
    host: '0.0.0.0',
    port: 5173,
    hmr: { host: 'localhost' },
},
```
Lalu cek service vite berjalan:
```bash
docker compose -f docker-compose.dev.yml logs -f vite
```

### ❌ Docker: node_modules kosong setelah restart
Node modules disimpan di Docker named volume. Install ulang jika volume kosong:
```bash
docker compose -f docker-compose.dev.yml exec app npm install
```

### ❌ Docker Production: "Vite manifest not found"
Asset frontend belum dibuild. Rebuild image:
```bash
docker compose up -d --build --force-recreate app
```
Atau build manual saat development:
```bash
docker compose exec app npm run build
```

### ❌ PostgreSQL: "connection refused" (lokal)
```bash
# Cek status PostgreSQL
sudo systemctl status postgresql   # Linux
brew services list | grep postgres  # Mac

# Test koneksi manual
psql -h 127.0.0.1 -U sakai -d sakai_laravel -p 5432

# Cek extension PHP terpasang
php -m | grep pgsql
```

### ❌ PostgreSQL: "FATAL: password authentication failed"
```bash
# Reset password di psql
psql -U postgres
ALTER USER sakai WITH PASSWORD 'secret';
\q
```

### ❌ Docker PostgreSQL: Container unhealthy / restart loop
```bash
# Lihat log PostgreSQL
docker compose logs postgres

# Test healthcheck manual
docker compose exec postgres pg_isready -U sakai -d sakai_laravel

# Hapus volume & mulai ulang (HATI-HATI: data hilang!)
docker compose down -v
docker compose up -d
```

### ❌ Error PHP: "could not find driver" (pdo_pgsql)
```bash
# Ubuntu/Debian
sudo apt install php8.4-pgsql

# Alpine (di dalam Docker)
# Sudah otomatis di Dockerfile & Dockerfile.dev

# Verifikasi
php -m | grep pdo
```

### ❌ Podman: "cannot find network"
```bash
podman-compose --podman-run-args="--network=host" up -d
```

---

## 🤝 Kontribusi

1. Fork repositori ini
2. Buat branch baru: `git checkout -b fitur/nama-fitur`
3. Commit perubahan: `git commit -m 'feat: tambah fitur xxx'`
4. Push ke branch: `git push origin fitur/nama-fitur`
5. Buat Pull Request

---

## 📄 Lisensi

Proyek ini menggunakan lisensi [MIT](LICENSE.md).

Template asli Sakai Vue oleh [PrimeFaces](https://github.com/primefaces/sakai-vue) — MIT License.

---

<div align="center">

Built with ❤️ using **Laravel** + **Vue 3** + **Inertia.js** + **PrimeVue** + **PHP 8.4** + **PostgreSQL**

</div>
