<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { ref, watch } from 'vue';

defineOptions({ layout: AppLayout });

defineProps({
    kegiatan: { type: Array, default: () => [] },
});

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

// Toast digerakkan flash server (pola yang sama dengan halaman lain).
watch(
    () => page.props.flash?.id,
    () => {
        const flash = page.props.flash ?? {};
        if (flash.error) toast.add({ severity: 'error', summary: 'Gagal', detail: flash.error, life: 5000 });
        if (flash.success) toast.add({ severity: 'success', summary: 'Berhasil', detail: flash.success, life: 3000 });
    },
);

const filters = ref({ global: { value: null, matchMode: 'contains' } });

// Tambah kegiatan.
const tambahForm = useForm({ nama: '' });
function tambah() {
    tambahForm.post(route('admin.kegiatan.store'), {
        preserveScroll: true,
        onSuccess: () => tambahForm.reset(),
    });
}

// Ubah nama (dialog).
const dialog = ref(false);
const editForm = useForm({ id: null, nama: '', aktif: true });
function bukaEdit(row) {
    editForm.clearErrors();
    editForm.id = row.id;
    editForm.nama = row.nama;
    editForm.aktif = row.aktif;
    dialog.value = true;
}
function simpan() {
    editForm.post(route('admin.kegiatan.update', editForm.id), {
        preserveScroll: true,
        onSuccess: () => { dialog.value = false; },
    });
}

function toggleAktif(row) {
    router.post(route('admin.kegiatan.update', row.id), { nama: row.nama, aktif: !row.aktif }, { preserveScroll: true });
}

function konfirmasiHapus(row) {
    confirm.require({
        message: `Hapus kegiatan "${row.nama}"?`,
        header: 'Konfirmasi',
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: 'Batal',
        acceptLabel: 'Hapus',
        acceptClass: 'p-button-danger',
        accept: () => router.delete(route('admin.kegiatan.destroy', row.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Master Kegiatan" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12">
            <div class="card">
                <DataTable
                    :value="kegiatan"
                    dataKey="id"
                    :paginator="kegiatan.length > 15"
                    :rows="15"
                    :filters="filters"
                    :globalFilterFields="['nama']"
                    responsiveLayout="scroll"
                    stripedRows
                >
                    <template #header>
                        <div class="flex flex-wrap justify-between items-end gap-4">
                            <div>
                                <h4 class="m-0 font-semibold text-xl">Master Kegiatan</h4>
                                <span class="text-muted-color text-sm">Daftar kegiatan baku yang dipilih Katimker saat mencatat tugas.</span>
                            </div>
                            <div class="flex flex-wrap items-start gap-3">
                                <IconField>
                                    <InputIcon><i class="pi pi-search" /></InputIcon>
                                    <InputText v-model="filters['global'].value" placeholder="Cari kegiatan..." />
                                </IconField>
                                <div class="flex flex-col gap-1">
                                    <div class="flex gap-2">
                                        <InputText
                                            v-model="tambahForm.nama"
                                            placeholder="Nama kegiatan baru"
                                            :class="{ 'p-invalid': tambahForm.errors.nama }"
                                            @keyup.enter="tambah"
                                        />
                                        <Button label="Tambah" icon="pi pi-plus" :loading="tambahForm.processing" :disabled="!tambahForm.nama.trim()" @click="tambah" />
                                    </div>
                                    <small v-if="tambahForm.errors.nama" class="text-red-500">{{ tambahForm.errors.nama }}</small>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">Belum ada kegiatan. Tambahkan kegiatan pertama di atas.</div>
                    </template>

                    <Column field="nama" header="Nama Kegiatan" sortable style="min-width:20rem" />
                    <Column field="tugas_count" header="Dipakai" sortable style="min-width:8rem">
                        <template #body="{ data }">{{ data.tugas_count }} tugas</template>
                    </Column>
                    <Column field="aktif" header="Status" sortable style="min-width:9rem">
                        <template #body="{ data }">
                            <Tag :value="data.aktif ? 'Aktif' : 'Nonaktif'" :severity="data.aktif ? 'success' : 'secondary'" />
                        </template>
                    </Column>
                    <Column :exportable="false" header="Aksi" style="min-width:12rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Button icon="pi pi-pencil" outlined rounded aria-label="Ubah nama" @click="bukaEdit(data)" />
                                <Button
                                    :icon="data.aktif ? 'pi pi-eye-slash' : 'pi pi-eye'"
                                    outlined rounded severity="secondary"
                                    :aria-label="data.aktif ? 'Nonaktifkan' : 'Aktifkan'"
                                    @click="toggleAktif(data)"
                                />
                                <Button
                                    v-if="!data.tugas_count"
                                    icon="pi pi-trash" outlined rounded severity="danger" aria-label="Hapus"
                                    @click="konfirmasiHapus(data)"
                                />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>

    <Dialog v-model:visible="dialog" header="Ubah Kegiatan" modal :style="{ width: '95vw', maxWidth: '28rem' }">
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                <label class="font-medium">Nama Kegiatan</label>
                <InputText v-model="editForm.nama" :class="{ 'p-invalid': editForm.errors.nama }" @keyup.enter="simpan" />
                <small v-if="editForm.errors.nama" class="text-red-500">{{ editForm.errors.nama }}</small>
                <small class="text-muted-color">Tugas lama tetap menyimpan nama kegiatan saat dicatat.</small>
            </div>
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" text @click="dialog = false" />
            <Button label="Simpan" icon="pi pi-check" :loading="editForm.processing" :disabled="!editForm.nama.trim()" @click="simpan" />
        </template>
    </Dialog>
</template>
