# 🚀 Sakai Laravel

<div align="center">

![Sakai Laravel Banner](https://primefaces.org/cdn/primevue/images/sakai-vue/sakai-vue-preview.jpg)

**Admin template berbasis Laravel + Vue 3 + Inertia.js + TailwindCSS 4 + PrimeVue 4**

[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?style=flat-square&logo=laravel)](https://laravel.com)
[![Vue](https://img.shields.io/badge/Vue-3.x-42B883?style=flat-square&logo=vue.js)](https://vuejs.org)
[![Inertia](https://img.shields.io/badge/Inertia.js-2.x-7C3AED?style=flat-square)](https://inertiajs.com)
[![TailwindCSS](https://img.shields.io/badge/TailwindCSS-4.x-06B6D4?style=flat-square&logo=tailwindcss)](https://tailwindcss.com)
[![PrimeVue](https://img.shields.io/badge/PrimeVue-4.x-41B883?style=flat-square)](https://primevue.org)
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
- 🐳 **Docker Ready** — Konfigurasi siap pakai untuk dev & production
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
| **Vite** | 5.x | Build tool super cepat |
| **Ziggy** | 2.x | Named routes Laravel di JavaScript |

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
│       ├── Dockerfile              # PHP 8.3 FPM Alpine
│       └── php-fpm.conf
│
├── docker-compose.yml              # Production (app + nginx + mysql + redis)
├── docker-compose.dev.yml          # Development (lebih ringan)
└── vite.config.js                  # Vite + TailwindCSS + PrimeVue
```

---

## ✅ Syarat Sistem

### Untuk Local Development
| Software | Versi Minimum |
|----------|--------------|
| PHP | 8.2+ |
| Composer | 2.x |
| Node.js | 18+ |
| npm | 9+ |
| Database | SQLite (default), MySQL 8.0, atau PostgreSQL |

### Untuk Docker / Podman
| Software | Platform |
|----------|---------|
| Docker Desktop | Windows, Mac, Linux |
| Docker Engine | Linux |
| Podman Desktop | Windows, Mac |
| Podman + podman-compose | Linux |

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

### 4. Setup database

**Opsi A — SQLite (paling cepat, tanpa install database):**
```bash
touch database/database.sqlite
# Pastikan .env berisi: DB_CONNECTION=sqlite
```

**Opsi B — MySQL:**
```bash
# Edit .env:
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_DATABASE=sakai_laravel
# DB_USERNAME=root
# DB_PASSWORD=yourpassword
```

### 5. Jalankan migrasi dan seeder

```bash
php artisan migrate --seed
```

### 6. Install dependensi Node

```bash
npm install
```

### 7. Jalankan server

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

File `.env` penting yang perlu diperhatikan:

```env
# Nama aplikasi (tampil di browser tab & navbar)
APP_NAME="Sakai Laravel"

# Mode debug — matikan di production!
APP_DEBUG=false

# URL aplikasi (sesuaikan dengan domain/port)
APP_URL=http://localhost:8000

# Database — pilih salah satu
DB_CONNECTION=sqlite
# atau
DB_CONNECTION=mysql
DB_HOST=mysql          # Gunakan nama service jika pakai Docker
DB_DATABASE=sakai_laravel
DB_USERNAME=sakai
DB_PASSWORD=secret

# Redis (opsional, untuk cache & queue)
REDIS_HOST=redis       # Gunakan nama service jika pakai Docker
```

---

## 🐳 Deploy dengan Docker

### Windows

#### Persiapan
1. Install **Docker Desktop** dari https://www.docker.com/products/docker-desktop/
2. Aktifkan WSL2 saat diminta (direkomendasikan)
3. Buka **PowerShell** atau **Terminal** (bukan CMD)

#### Langkah-langkah

```powershell
# Clone project
git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel

# Salin env dan sesuaikan
Copy-Item .env.example .env

# Edit .env untuk MySQL (bukan SQLite)
# DB_CONNECTION=mysql
# DB_HOST=mysql
# DB_DATABASE=sakai_laravel
# DB_USERNAME=sakai
# DB_PASSWORD=secret
```

Edit file `.env`, lalu:

```powershell
# Build dan jalankan semua service
docker compose up -d --build

# Tunggu sekitar 2-3 menit untuk build pertama
# Cek status
docker compose ps

# Generate app key
docker compose exec app php artisan key:generate

# Jalankan migrasi + seeder
docker compose exec app php artisan migrate --seed

# Build frontend assets
docker compose exec app npm run build
```

Buka browser: **http://localhost:8000**

#### Perintah berguna

```powershell
# Melihat log
docker compose logs -f

# Masuk ke container
docker compose exec app bash

# Stop semua service
docker compose down

# Stop dan hapus volume (reset database)
docker compose down -v
```

---

### Mac / Linux

#### Persiapan (Mac)
1. Install **Docker Desktop** dari https://www.docker.com/products/docker-desktop/
2. Atau install **OrbStack** (lebih ringan di Mac): https://orbstack.dev

#### Persiapan (Linux)
```bash
# Install Docker Engine
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker

# Install Docker Compose plugin
sudo apt install docker-compose-plugin  # Ubuntu/Debian
sudo dnf install docker-compose-plugin  # Fedora/RHEL
```

#### Langkah-langkah

```bash
# Clone project
git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel

# Salin env
cp .env.example .env

# Edit untuk MySQL
nano .env  # atau: code .env / vim .env
# Ubah: DB_CONNECTION=mysql, DB_HOST=mysql

# Build dan jalankan
docker compose up -d --build

# Tunggu build selesai, lalu:
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app npm run build
```

Buka browser: **http://localhost:8000**

---

### Development Mode dengan Docker

Untuk development dengan hot-reload Vite:

```bash
# Jalankan hanya MySQL dan Redis via Docker
docker compose -f docker-compose.dev.yml up -d mysql redis

# Edit .env: DB_HOST=127.0.0.1, REDIS_HOST=127.0.0.1

# Jalankan Laravel dan Vite di host
php artisan serve &
npm run dev
```

---

## 🦭 Deploy dengan Podman

Podman adalah alternatif Docker yang **tidak membutuhkan daemon** dan berjalan rootless (lebih aman).

### Windows (dengan Podman Desktop)

1. Install **Podman Desktop** dari https://podman-desktop.io
2. Buka Podman Desktop, klik "Initialize and start" untuk Podman Machine

```powershell
# Install podman-compose
pip install podman-compose

# Clone dan setup
git clone https://github.com/username/sakai-laravel.git
cd sakai-laravel
Copy-Item .env.example .env
# Edit .env seperti Docker setup

# Jalankan dengan podman-compose
podman-compose -f docker-compose.yml up -d --build

# Setup aplikasi
podman exec -it sakai_app php artisan key:generate
podman exec -it sakai_app php artisan migrate --seed
podman exec -it sakai_app npm run build
```

### Mac

```bash
# Install Podman
brew install podman podman-compose

# Initialize Podman machine (Mac membutuhkan VM)
podman machine init
podman machine start

# Jalankan project
cd sakai-laravel
podman-compose up -d --build

# Setup
podman exec -it sakai_app php artisan key:generate
podman exec -it sakai_app php artisan migrate --seed
```

### Linux

```bash
# Install Podman (Ubuntu/Debian)
sudo apt install podman

# Install podman-compose
pip3 install podman-compose
# atau
sudo apt install podman-compose

# Jalankan (tidak perlu sudo — rootless!)
cd sakai-laravel
podman-compose up -d --build

# Setup
podman exec -it sakai_app php artisan key:generate
podman exec -it sakai_app php artisan migrate --seed
```

> **Catatan Podman:** Jika ada issue dengan nama host antar container, tambahkan `--network=host` atau gunakan `host.containers.internal` sebagai hostname.

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
Tampilan ke User
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
|----------|--------------------|
| Email    | admin@sakai.dev    |
| Password | password           |

---

## 🔧 Perintah Berguna

```bash
# Development
php artisan serve          # Jalankan Laravel
npm run dev                # Jalankan Vite dengan HMR

# Database
php artisan migrate        # Jalankan migrasi baru
php artisan migrate:fresh --seed  # Reset + seed ulang
php artisan db:seed        # Seed tanpa reset

# Cache
php artisan config:clear   # Clear config cache
php artisan route:clear    # Clear route cache
php artisan view:clear     # Clear view cache
php artisan optimize:clear # Clear semua cache

# Production
php artisan optimize       # Cache config + routes
npm run build              # Build frontend untuk production

# Testing
php artisan test           # Jalankan semua test
```

---

## 🐛 Troubleshooting

### ❌ Error: "Route [xxx] not defined"
Ziggy route tidak dimuat. Pastikan:
1. `@routes` ada di `app.blade.php` (sudah ada)
2. Jalankan `php artisan route:clear`
3. Cek nama route di `web.php` sesuai dengan yang dipanggil di `AppMenu.vue`

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
# Lalu restart
docker compose down && docker compose up -d
```

### ❌ Permission denied di storage/
```bash
chmod -R 775 storage bootstrap/cache
# atau di Docker:
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### ❌ Podman: "cannot find network"
```bash
# Jalankan dengan host network
podman-compose --podman-run-args="--network=host" up -d
```

### ❌ SQLite tidak ditemukan
```bash
touch database/database.sqlite
php artisan migrate --seed
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

Built with ❤️ using **Laravel** + **Vue 3** + **Inertia.js** + **PrimeVue**

</div>
