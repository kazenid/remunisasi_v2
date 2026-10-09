---
name: sakai-ui-ux
description: Panduan membangun UI/UX di proyek remunisasi_v2 dengan Sakai (PrimeVue + Aura + Inertia + Vue 3 + Tailwind) sebagai fondasi. Pakai saat membuat atau mengubah halaman/komponen Vue, layout, form, tabel, atau apa pun yang tampil ke pengguna. Utamakan komponen Sakai/PrimeVue yang sudah ada; jangan membuat ulang yang tak perlu. Pertahankan tata letak dan tipografi Sakai.
---

# Sakai UI/UX — Panduan Proyek remunisasi_v2

Fondasi UI proyek ini adalah **Sakai** (template admin PrimeFaces untuk Vue). Stack: **Inertia.js + Vue 3 + PrimeVue (tema Aura) + Tailwind v4 + tailwindcss-primeui**. Tujuan panduan ini: UI baru terasa menyatu dengan Sakai, bukan tempelan.

## Prinsip utama

1. **Pakai komponen PrimeVue yang sudah ada. Jangan membuat ulang.** Button, InputText, Select, DatePicker, DataTable, Dialog, Tag, Toast, dsb. sudah tersedia global. Membuat tombol/kartu/tabel custom dari nol = salah, kecuali memang tidak ada padanannya di PrimeVue.
2. **Pertahankan tata letak & tipografi Sakai.** Grid 12 kolom, kelas `.card`, variabel surface/text, skala font, dan jarak yang sudah ada adalah standar. Jangan memperkenalkan sistem spacing/warna/tipografi baru.
3. **Jangan import komponen PrimeVue secara manual.** Komponen PrimeVue teregistrasi global (lihat `resources/js/app.js`). Tulis `<Button>`, `<DataTable>`, dll. langsung di template.
4. **Pakai token tema, bukan warna mentah.** Gunakan `var(--p-*)` dan utilitas Tailwind-primeui (`text-primary`, `text-muted-color`, `bg-surface-*`) agar dark mode ikut benar.
5. **Hormati dark mode.** Selektor dark adalah `.app-dark`. Pasangkan kelas terang/gelap seperti contoh Sakai (`text-surface-900 dark:text-surface-0`).

## Yang SUDAH ada (jangan buat ulang)

- **Layout utama:** `@/Layouts/AppLayout.vue` — topbar + sidebar + footer + Toast + ConfirmDialog global. Semua halaman konten memakai ini.
- **Layout tamu:** `@/Layouts/GuestLayout.vue` — untuk halaman seperti login.
- **Navigasi:** `@/layout/AppMenu.vue` (model menu), `AppMenuItem`, `AppSidebar`, `AppTopbar`, `AppFooter`.
- **Composable layout:** `@/layout/composables/layout.js` (`useLayout()` → menuMode, dark mode, dsb.).
- **Kelas CSS siap pakai** (di `resources/css/app.css`): `.card`, `.layout-*`, animasi `.animate-fadein`/`.animate-slidedown`, `.rounded-border`.
- **Services global:** `useToast()`, `useConfirm()` (PrimeVue), sudah dipasang di `app.js` dan dirender di `AppLayout`.

Sebelum membuat komponen baru, cek dulu `resources/js/Components`, `resources/js/layout`, dan `resources/js/Pages/UIKit` — kemungkinan polanya sudah ada untuk dicontek.

## Pola halaman standar

Setiap halaman konten adalah komponen Inertia dengan persistent layout:

```vue
<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });

defineProps({ /* props dari controller Laravel */ });
</script>

<template>
    <Head title="Judul Halaman" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <!-- konten -->
            </div>
        </div>
    </div>
</template>
```

- Judul tab lewat `<Head title="...">`. App title digabung otomatis (lihat `app.js`).
- Pembungkus konten: `grid grid-cols-12 gap-8`, lalu `col-span-*` (dengan breakpoint `lg:` / `xl:`).
- Blok konten dibungkus `.card`. Gunakan `card mb-0` bila kartu berada di dalam grid widget.

## Tata letak & grid

- Selalu grid 12 kolom: `grid grid-cols-12 gap-8`.
- Kartu statistik: `col-span-12 lg:col-span-6 xl:col-span-3`.
- Dua kolom besar: `col-span-12 xl:col-span-6`.
- Jangan pakai lebar/padding hardcode untuk tata letak halaman; pakai grid + `.card`.

## Token tema (WAJIB diperhatikan)

- Skala `--p-surface-0..950` disuntik PrimeVue **runtime** dan hanya ada bila surface palette ditetapkan saat boot. Di proyek ini sudah dipasang di `resources/js/app.js` via `definePreset(Aura, { semantic.colorScheme.light/dark.surface })` (palette slate). **Jangan hapus** itu — tanpanya `text-surface-*`, `bg-surface-*`, dan alias layout jadi kosong sehingga teks "hilang" di mode light dan permukaan transparan.
- Alias kompat Sakai lama (`--p-surface-card/ground/border/hover`) didefinisikan di `resources/css/app.css` (`:root` + `.app-dark`) memetakan ke token Aura nyata. Pakai alias ini untuk permukaan layout; jangan perkenalkan nilai warna mentah.
- Selalu uji tampilan di mode light DAN dark sebelum selesai (toggle di topbar). Teks di atas kartu: `text-surface-900 dark:text-surface-0`.

## Tipografi & warna (ikuti Sakai)

- Teks utama angka/judul kartu: `text-surface-900 dark:text-surface-0 font-medium text-xl`.
- Label sekunder: `text-muted-color font-medium` (atau `block ... mb-4`).
- Aksen: `text-primary`.
- Latar ikon kategori: `bg-{warna}-100 dark:bg-{warna}-400/10 rounded-border`, ikon `pi pi-* text-{warna}-500`.
- Ukuran font dasar aplikasi 14px (`html { font-size: 14px }`), jadi gunakan skala Tailwind apa adanya; jangan override font-size global.
- Ikon memakai **PrimeIcons** (`pi pi-fw pi-...`). Jangan menambah pustaka ikon lain.

## Komponen PrimeVue yang dipakai (contoh pemetaan kebutuhan)

| Kebutuhan | Komponen PrimeVue |
|---|---|
| Tombol aksi | `<Button label icon severity>` |
| Input teks | `<InputText>` |
| Input angka | `<InputNumber>` |
| Password | `<Password :feedback="false" toggleMask>` |
| Pilihan tunggal | `<Select>` (bukan Dropdown lama) |
| Tanggal | `<DatePicker>` |
| Tabel data | `<DataTable>` + `<Column>` |
| Dialog/modal | `<Dialog>` |
| Label status | `<Tag :severity>` |
| Checkbox | `<Checkbox binary>` |
| Unggah berkas | `<FileUpload>` |
| Notifikasi | `useToast()` |
| Konfirmasi | `useConfirm()` / `<ConfirmDialog>` |

Severity standar: `success`, `info`, `warn`, `danger`, `secondary`, `contrast`.

## Pola form (Inertia + PrimeVue)

Gunakan `useForm` Inertia untuk state + submit, dan tampilkan error dari server:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const form = useForm({ judul: '', durasi: 0 });

function submit() {
    form.post(route('contoh.store'), {
        onSuccess: () => toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Tersimpan', life: 3000 }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-2 mb-4">
        <label for="judul" class="font-medium">Judul</label>
        <InputText id="judul" v-model="form.judul" :class="{ 'p-invalid': form.errors.judul }" />
        <small v-if="form.errors.judul" class="text-red-500">{{ form.errors.judul }}</small>
    </div>
    <Button label="Simpan" :loading="form.processing" @click="submit" />
</template>
```

- Tandai field invalid dengan `:class="{ 'p-invalid': form.errors.x }"` dan tampilkan `<small class="text-red-500">`.
- Tombol submit pakai `:loading="form.processing"`.
- Pesan sukses/gagal lewat `useToast()`; konfirmasi hapus lewat `useConfirm()` (lihat pola di `Pages/Pages/Crud.vue`).

## Pola tabel data

Contek `resources/js/Pages/Pages/Crud.vue`: `<DataTable>` dengan `:value`, `paginator`, `:rows`, `filters` (global search), kolom via `<Column>`, status pakai `<Tag :severity>`, aksi baris pakai `<Button icon rounded outlined>`. Dialog tambah/edit pakai `<Dialog>` + `useForm`.

## Bahasa

UI aplikasi ini berbahasa **Indonesia** (lihat Login: "Masuk", "Ingat saya"). Label, placeholder, pesan toast, dan judul ditulis dalam bahasa Indonesia. Nama variabel/kode tetap boleh Inggris.

## Checklist sebelum selesai

- [ ] Memakai `AppLayout` (atau `GuestLayout`) dan `<Head>`.
- [ ] Tata letak pakai grid 12 kolom + `.card`.
- [ ] Komponen diambil dari PrimeVue global, bukan dibuat ulang.
- [ ] Warna/tipografi pakai token surface/text/primary; dark mode diperhatikan (`dark:`).
- [ ] Form pakai `useForm`, error server ditampilkan, toast untuk feedback.
- [ ] Ikon dari PrimeIcons (`pi pi-*`).
- [ ] Teks UI dalam bahasa Indonesia.
- [ ] `npm run build` lolos.
