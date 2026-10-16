# Laporan: Halaman putih (blank) di stack dev Docker

## 1. Gejala yang direproduksi

- Stack dev berjalan sehat: `tukiman_app_dev`, `tukiman_nginx_dev` (:8085), `tukiman_vite_dev` (:5173, log "ready"), `tukiman_redis_dev` (healthy), `tukiman_mysql_dev` (healthy). Nama container di repo ini `tukiman_*`, bukan `sakai_*`.
- Sisi server normal: `curl http://localhost:8085/login` = 200, `public/hot` berisi `http://localhost:5173`, `http://localhost:5173/@vite/client` dan `/resources/js/app.js` = 200 (`text/javascript`). Tidak ada 500 di log app/nginx.
- Reproduksi di browser (Chrome headless, CDP): `#app` terisi, tetapi tidak ada form. Console:
  - `[Vue warn]: Unhandled error during execution of setup function`
  - `Uncaught (in promise) ReferenceError: ref is not defined` di `resources/js/Pages/Auth/Login.vue`

## 2. Akar masalah (dengan bukti)

Ada dua penyebab yang saling menguatkan. Bukan masalah Docker, `.dockerignore`, `public/hot`, vendor/node_modules, CRLF, atau nginx (semua dikesampingkan: aset 200, tidak ada error server, `.gitattributes` memaksa `eol=lf`).

1. **Regresi kode di `Login.vue` (penyebab langsung).** Commit `9537173` ("add asset helper composable ... subdirectory deployment") mengubah impor `import { computed, onBeforeUnmount, onMounted, ref } from 'vue'` menjadi `import { computed } from 'vue'`, padahal `<script setup>` masih memakai `ref`, `onMounted`, `onBeforeUnmount` (blok Turnstile). `setup()` melempar error sehingga halaman login tidak dirender -> layar putih. Bug ini ikut ke build produksi (Vue tidak auto-import), jadi bukan khusus dev. Bukti: diff commit tersebut dan pesan console di atas. Pemindaian seluruh `resources/js` untuk pemakaian `ref/reactive/computed/watch/onMounted/...` tanpa impor: hanya `Login.vue` yang bermasalah.
2. **Vite menyajikan modul basi di Docker Desktop Windows (penyebab kenapa perbaikan tidak langsung terlihat).** Bind mount `.:/var/www` dari Windows tidak meneruskan event inotify ke container, sehingga watcher Vite tidak tahu berkas berubah. Bukti: setelah `Login.vue` diperbaiki (container melihat isi baru lewat `docker exec ... sed -n 5p`), `curl http://localhost:5173/resources/js/Pages/Auth/Login.vue` tetap menyajikan `import { computed }` lama dan Chrome tetap error. Setelah polling diaktifkan, edit dan revert terdeteksi (log Vite: `hmr update /resources/js/Pages/Auth/Login.vue`).

## 3. Berkas yang diubah

- `resources/js/Pages/Auth/Login.vue`: kembalikan impor `computed, onBeforeUnmount, onMounted, ref` dari `vue`.
- `vite.config.js`: `server.watch.usePolling = process.env.VITE_USE_POLLING === 'true'` (interval 300 ms). Default mati, jadi `npm run dev` di host dan build produksi tidak berubah.
- `docker-compose.dev.yml` (service `vite`): `VITE_USE_POLLING: "true"`.
- `README.md`: bagian "Halaman putih / blank" di Pemecahan masalah ditulis ulang (cek Console, cek Vite, catatan polling Windows).
- Makefile tidak diubah (`make dev-logs SERVICE=vite` sudah cukup). Tidak ada perubahan Dockerfile/.dockerignore.

Catatan: selama sesi ini, perubahan `Login.vue`, `vite.config.js`, dan `docker-compose.dev.yml` ternyata sudah di-commit oleh pihak lain sebagai `e165f38` ("fix(dev): configure vite polling for windows and add turnstile lifecycle hooks"). Saya tidak membuat commit. Hanya `README.md` yang masih belum di-commit.

## 4. Verifikasi setelah perbaikan

- `docker compose -f docker-compose.dev.yml up -d vite` (recreate hanya container vite), log "VITE ... ready".
- Chrome headless via CDP ke `http://localhost:8085/login`: judul "Masuk — TUKIMAN", `inputs: 3`, `buttons: 33`, teks "Selamat datang ... Username / No. Absen / No. Pegawai, Password, Ingat saya, Masuk". Console hanya `[vite] connecting/connected`; tidak ada exception; tidak ada respons HTTP >= 400 atau request gagal.
- Uji polling: menyisipkan `console.log('polltest-marker')` ke `Login.vue` lalu mengembalikannya; Vite menyajikan perubahan dan revert (2 log `hmr update`). Berkas dipulihkan persis.
- `npx vite build` (ke direktori sementara, sudah dihapus): sukses, chunk `Login-*.js` terbentuk. `docker compose -f docker-compose.dev.yml config -q`: sukses.
- Alur "clone bersih": stack dev tidak bergantung pada `vendor`/`node_modules` host (memakai volume bernama `tukiman_vendor_dev`/`tukiman_node_modules_dev`; `composer install` dan `npm install` jalan saat start). `public/hot` dibuat oleh container vite. `.gitattributes` memaksa LF. Yang butuh `.env` (salin dari `.env.example` + `APP_KEY`) tidak saya ubah. Clone bersih penuh tidak saya simulasikan dari nol (butuh menghapus volume; dilarang).

## 5. Langkah bagi user

```powershell
git pull                      # atau pastikan Login.vue + vite.config.js + docker-compose.dev.yml terbaru
docker compose -f docker-compose.dev.yml up -d --build vite
docker compose -f docker-compose.dev.yml logs --tail=20 vite   # tunggu "ready"
```

Lalu buka http://localhost:8085/login dan tekan Ctrl+F5. Untuk build produksi, rebuild image (`docker compose up -d --build`) karena bug `Login.vue` juga ada di sana.

## 6. Tidak terverifikasi

- Browser Windows asli milik user (saya memakai Chrome headless di mesin ini; hasil seharusnya sama, tetapi cache browser perlu di-hard-refresh).
- Build produksi Docker penuh tidak dijalankan; hanya `vite build` lokal. Turnstile dengan site key aktif tidak diuji (hook `onMounted` hanya jalan bila key ada).
- Pemakaian CPU polling jangka panjang (container vite sekitar 20% CPU saat idle di pengukuran sesaat).
- Cara `virtual-time-budget` Chrome `--dump-dom` menggantung pada halaman yang sudah render (kemungkinan terkait timer/font eksternal); saya beralih ke CDP. Tidak diselidiki lebih jauh.
