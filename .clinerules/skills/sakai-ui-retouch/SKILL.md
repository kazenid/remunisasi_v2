---
name: sakai-ui-retouch
description: Retouch UI Laravel Inertia Vue berbasis Sakai Vue agar modern, rapi, informatif, responsif, dan mudah digunakan tanpa mengganti komponen Sakai atau mengubah alur bisnis.
---

# Sakai UI Retouch

## Tujuan

Gunakan skill ini saat memperbaiki tampilan aplikasi E-Remunerasi RSUD Bangil. Pertahankan Sakai Vue, PrimeVue Aura, Tailwind CSS, Inertia Vue, dan komponen layout yang sudah tersedia. Fokus pada tata letak, hierarchy informasi, spacing, typography, responsive behavior, accessibility, dan branding aplikasi.

## Batasan wajib

- Jangan mengganti template Sakai Vue.
- Jangan menambah dependency untuk kebutuhan visual yang dapat diselesaikan dengan CSS atau komponen PrimeVue yang sudah terpasang.
- Jangan mengubah route, controller, API, validasi backend, permission, atau alur bisnis.
- Jangan membuat komponen abstrak baru jika perubahan dapat dilakukan pada layout atau CSS global.
- Reuse komponen Sakai/PrimeVue yang sudah dipakai.
- Gunakan absolute path saat menyebut file.
- Pertahankan dark mode dan mobile layout.
- Jangan menghapus informasi penting demi estetika.

## Arah visual

Gunakan arah visual **clinical editorial**:

- Bersih, tenang, profesional, dan mudah dipindai.
- Cocok untuk aplikasi kinerja dan insentif rumah sakit.
- Gunakan primary color dari PrimeVue, bukan palette baru yang terpisah.
- Gunakan border halus, shadow ringan, radius konsisten, dan whitespace terkontrol.
- Hindari gradient berlebihan, glassmorphism berat, animasi dekoratif, dan layout yang ramai.
- Utamakan angka, status, tindakan utama, lalu detail pendukung.

## File prioritas

Audit file dalam urutan berikut:

1. `resources/css/app.css`
2. `resources/js/Layouts/AppLayout.vue`
3. `resources/js/Layouts/GuestLayout.vue`
4. `resources/js/layout/AppTopbar.vue`
5. `resources/js/layout/AppSidebar.vue`
6. `resources/js/layout/AppMenu.vue`
7. `resources/js/layout/AppMenuItem.vue`
8. Halaman fitur terkait di `resources/js/Pages/`

## Pola layout

- Gunakan `grid grid-cols-12` untuk dashboard dan halaman data.
- Gunakan `gap-4` atau `gap-6` secara konsisten; jangan mencampur spacing tanpa alasan.
- Gunakan `.card` untuk section utama, bukan wrapper visual baru.
- Judul section harus jelas, singkat, dan memiliki deskripsi jika konteksnya tidak langsung.
- Letakkan action utama di sisi kanan desktop dan susun vertikal di mobile.
- Table tetap memakai `DataTable` dan `Column` PrimeVue.
- Dialog tetap memakai `Dialog`, `Button`, dan komponen PrimeVue yang sudah ada.
- Form harus memiliki label, error state, autocomplete yang sesuai, dan target sentuh yang nyaman.

## Typography

- Pertahankan `var(--p-font-family)` dari tema PrimeVue.
- Gunakan heading untuk hierarchy, bukan hanya teks bold.
- Body text harus memiliki line-height yang nyaman.
- Metadata gunakan ukuran kecil dan warna muted, tetapi tetap memiliki kontras yang cukup.
- Status dan angka penting harus memiliki visual emphasis yang jelas.
- Hindari penggunaan `text-[10px]` untuk informasi yang harus dibaca pengguna; gunakan minimal ukuran yang tetap terbaca.

## CSS global

Perubahan global sebaiknya memakai token di `:root`, misalnya:

- `--topbar-height`
- `--sidebar-width`
- `--content-padding`
- `--content-max-width`
- `--border-radius`
- `--focus-ring`
- `--app-shadow`

Gunakan `color-mix()` hanya jika didukung oleh target browser aplikasi dan tetap fallback secara wajar. Jangan hardcode warna yang bertentangan dengan dark mode.

## Accessibility

- Semua tombol icon-only wajib memiliki `aria-label` atau `title` yang bermakna.
- Semua input wajib memiliki label terkait.
- Gunakan `:focus-visible` yang jelas.
- Jangan mengandalkan warna saja untuk status.
- Pertahankan keyboard navigation pada menu, dialog, table, dan form.
- Hormati `prefers-reduced-motion`.
- Pastikan layout tidak menyebabkan horizontal overflow pada viewport kecil.

## Proses kerja

1. Baca layout global dan halaman terkait sebelum mengedit.
2. Identifikasi masalah hierarchy, density, spacing, typography, responsive, dan accessibility.
3. Pilih perubahan terkecil yang memperbaiki masalah terbesar.
4. Utamakan CSS/layout global sebelum mengedit banyak halaman.
5. Edit file sesedikit mungkin.
6. Jangan menyentuh business logic.
7. Jalankan `npm run build` setelah perubahan frontend.
8. Jalankan `git diff --check` dan periksa hasil perubahan.
9. Baca ulang file yang diedit untuk memastikan tidak ada tag Vue rusak, class typo, atau style duplikat.

## Checklist review

- [ ] Sakai/PrimeVue component tetap digunakan.
- [ ] Tidak ada dependency baru.
- [ ] Tidak ada perubahan API atau business logic.
- [ ] Desktop dan mobile tertata.
- [ ] Dark mode tetap terbaca.
- [ ] Focus state terlihat.
- [ ] Action utama mudah ditemukan.
- [ ] Status/angka penting mudah dipindai.
- [ ] Tidak ada horizontal overflow yang tidak perlu.
- [ ] `npm run build` berhasil.
- [ ] File hasil edit sudah dibaca ulang.

## Validasi minimal

```powershell
npm run build
git diff --check
```

Jika perubahan hanya CSS/layout, tidak perlu membuat test framework baru. Build production adalah validasi utama; gunakan screenshot/manual browser check jika tersedia.

## Simplifikasi yang disengaja

`ponytail: skill ini tidak membuat design system baru; gunakan token PrimeVue dan class Sakai yang sudah ada. Tambahkan abstraction hanya jika pola UI yang sama muncul di minimal tiga halaman.`
