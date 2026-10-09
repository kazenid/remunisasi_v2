<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { ref, reactive, watch } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    users: { type: Array, default: () => [] },
    hasilCari: { type: Array, default: () => [] },
    q: { type: String, default: '' },
    roleOptions: { type: Array, default: () => [] },
});

const toast = useToast();
const confirm = useConfirm();
const page = usePage();

const filters = ref({ global: { value: null, matchMode: 'contains' } });
const cari = ref(props.q);

// Pilihan role per baris daftar user lokal (diinit dari data server).
const pilihanRole = reactive({});
props.users.forEach((u) => {
    pilihanRole[u.id] = u.role;
});

const savingRoleId = ref(null);

// Dialog tarik pegawai.
const dialogTarik = ref(false);
const pegawaiDipilih = ref(null);
const roleTarik = ref(null);
const prosesTarik = ref(false);

watch(
    () => page.props.flash?.error,
    (msg) => { if (msg) toast.add({ severity: 'error', summary: 'Gagal', detail: msg, life: 5000 }); },
    { immediate: true },
);

function cariPegawai() {
    router.get(route('admin.pengguna.index'), { q: cari.value }, { preserveState: true, preserveScroll: true });
}

function roleSeverity(role) {
    if (role === 'admin') return 'danger';
    if (role === 'katimker') return 'info';
    if (role === 'kabid') return 'warn';
    return 'secondary';
}

function roleLabel(role) {
    const opt = props.roleOptions.find((o) => o.value === role);
    return opt ? opt.label : '—';
}

function simpanRole(user) {
    const role = pilihanRole[user.id];
    if (!role) {
        toast.add({ severity: 'warn', summary: 'Belum lengkap', detail: 'Pilih peran terlebih dahulu.', life: 3000 });
        return;
    }

    savingRoleId.value = user.id;
    router.post(route('admin.pengguna.role', user.id), { role }, {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Peran pengguna diperbarui.', life: 3000 }),
        onError: (e) => toast.add({ severity: 'error', summary: 'Gagal', detail: Object.values(e)[0] ?? 'Terjadi kesalahan.', life: 4000 }),
        onFinish: () => (savingRoleId.value = null),
    });
}

function toggleAktif(user) {
    const jadiAktif = !user.aktif;
    confirm.require({
        message: jadiAktif
            ? `Aktifkan kembali akun "${user.nama}"?`
            : `Nonaktifkan akun "${user.nama}"? Pengguna tidak dapat login hingga diaktifkan kembali.`,
        header: 'Konfirmasi',
        icon: 'pi pi-power-off',
        rejectLabel: 'Batal',
        acceptLabel: jadiAktif ? 'Aktifkan' : 'Nonaktifkan',
        acceptClass: jadiAktif ? '' : 'p-button-danger',
        accept: () => {
            router.post(route('admin.pengguna.aktif', user.id), { aktif: jadiAktif }, {
                preserveScroll: true,
                onSuccess: () => toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Status aktif pengguna diperbarui.', life: 3000 }),
                onError: (e) => toast.add({ severity: 'error', summary: 'Gagal', detail: Object.values(e)[0] ?? 'Terjadi kesalahan.', life: 4000 }),
            });
        },
    });
}

function bukaDialogTarik(pegawai) {
    pegawaiDipilih.value = pegawai;
    roleTarik.value = null;
    dialogTarik.value = true;
}

function tarikPegawai() {
    if (!roleTarik.value) {
        toast.add({ severity: 'warn', summary: 'Belum lengkap', detail: 'Pilih peran terlebih dahulu.', life: 3000 });
        return;
    }

    prosesTarik.value = true;
    router.post(route('admin.pengguna.tarik'), {
        sikep_user_id: pegawaiDipilih.value.sikep_user_id,
        role: roleTarik.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            dialogTarik.value = false;
            toast.add({ severity: 'success', summary: 'Berhasil', detail: 'Pegawai ditarik dan peran ditetapkan.', life: 3000 });
        },
        onError: (e) => toast.add({ severity: 'error', summary: 'Gagal', detail: Object.values(e)[0] ?? 'Terjadi kesalahan.', life: 4000 }),
        onFinish: () => (prosesTarik.value = false),
    });
}
</script>

<template>
    <Head title="Manajemen Pengguna" />

    <div class="grid grid-cols-12 gap-8">
        <!-- Daftar pengguna lokal -->
        <div class="col-span-12">
            <div class="card">
                <DataTable
                    :value="users"
                    dataKey="id"
                    :paginator="users.length > 10"
                    :rows="10"
                    :filters="filters"
                    :globalFilterFields="['nama', 'username', 'no_absen']"
                    responsiveLayout="scroll"
                    stripedRows
                >
                    <template #header>
                        <div class="flex flex-wrap justify-between items-center gap-4">
                            <div>
                                <h4 class="m-0 font-semibold text-xl">Manajemen Pengguna</h4>
                                <span class="text-muted-color text-sm">Pengguna yang sudah ditarik ke aplikasi. Peran ditetapkan manual oleh administrator.</span>
                            </div>
                            <IconField>
                                <InputIcon><i class="pi pi-search" /></InputIcon>
                                <InputText v-model="filters['global'].value" placeholder="Cari pengguna..." />
                            </IconField>
                        </div>
                    </template>

                    <template #empty>
                        <div class="text-center text-muted-color py-6">Belum ada pengguna terdaftar.</div>
                    </template>

                    <Column field="nama" header="Nama" sortable style="min-width:16rem">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-surface-900 dark:text-surface-0">{{ data.nama }}</span>
                                <span class="text-muted-color text-sm">{{ data.username }}<template v-if="data.no_absen"> · {{ data.no_absen }}</template></span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Peran" style="min-width:8rem">
                        <template #body="{ data }">
                            <Tag v-if="data.role" :value="roleLabel(data.role)" :severity="roleSeverity(data.role)" />
                            <span v-else class="text-muted-color text-sm">—</span>
                        </template>
                    </Column>
                    <Column header="Status" style="min-width:8rem">
                        <template #body="{ data }">
                            <Tag :value="data.aktif ? 'Aktif' : 'Nonaktif'" :severity="data.aktif ? 'success' : 'secondary'" />
                        </template>
                    </Column>
                    <Column header="Sync Terakhir" style="min-width:12rem">
                        <template #body="{ data }"><span class="text-muted-color text-sm">{{ data.last_sync_at ?? '—' }}</span></template>
                    </Column>
                    <Column header="Ubah Peran" style="min-width:18rem">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Select
                                    v-model="pilihanRole[data.id]"
                                    :options="roleOptions"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Pilih peran"
                                    class="w-full"
                                />
                                <Button
                                    icon="pi pi-check"
                                    label="Simpan"
                                    size="small"
                                    :loading="savingRoleId === data.id"
                                    :disabled="!pilihanRole[data.id] || pilihanRole[data.id] === data.role"
                                    @click="simpanRole(data)"
                                />
                            </div>
                        </template>
                    </Column>
                    <Column header="Status Akun" style="min-width:10rem">
                        <template #body="{ data }">
                            <Button
                                v-if="data.aktif"
                                label="Nonaktifkan" icon="pi pi-ban" size="small" severity="danger" outlined
                                @click="toggleAktif(data)"
                            />
                            <Button
                                v-else
                                label="Aktifkan" icon="pi pi-check-circle" size="small" severity="success" outlined
                                @click="toggleAktif(data)"
                            />
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>

        <!-- Cari & tarik pegawai SIKEP -->
        <div class="col-span-12">
            <div class="card">
                <h4 class="m-0 mb-2 font-semibold text-xl">Tarik Pegawai SIKEP</h4>
                <span class="text-muted-color text-sm">Telusuri pegawai aktif SIKEP lalu tarik ke aplikasi dengan menetapkan peran.</span>

                <div class="flex gap-2 mt-4 mb-4">
                    <InputText v-model="cari" placeholder="Nama / username / no. absen (min. 3 huruf)" class="w-full md:w-[28rem]" @keyup.enter="cariPegawai" />
                    <Button label="Cari" icon="pi pi-search" @click="cariPegawai" />
                </div>

                <DataTable v-if="hasilCari.length" :value="hasilCari" dataKey="sikep_user_id" responsiveLayout="scroll" stripedRows>
                    <Column field="nama" header="Nama" style="min-width:16rem">
                        <template #body="{ data }">
                            <div class="flex flex-col">
                                <span class="font-medium text-surface-900 dark:text-surface-0">{{ data.nama }}</span>
                                <span class="text-muted-color text-sm">{{ data.username }}<template v-if="data.no_absen"> · {{ data.no_absen }}</template></span>
                            </div>
                        </template>
                    </Column>
                    <Column header="Status" style="min-width:12rem">
                        <template #body="{ data }">
                            <Tag v-if="data.sudah_ditarik && data.role" :value="roleLabel(data.role)" :severity="roleSeverity(data.role)" />
                            <Tag v-else-if="data.sudah_ditarik" value="Sudah ditarik" severity="secondary" />
                            <span v-else class="text-muted-color text-sm">Belum ditarik</span>
                        </template>
                    </Column>
                    <Column header="Aksi" style="min-width:10rem">
                        <template #body="{ data }">
                            <span v-if="data.sudah_ditarik" v-tooltip.top="'Sudah ditarik — kelola di daftar user lokal'">
                                <Button label="Tarik" icon="pi pi-download" size="small" outlined disabled />
                            </span>
                            <Button
                                v-else
                                label="Tarik" icon="pi pi-download" size="small" outlined
                                @click="bukaDialogTarik(data)"
                            />
                        </template>
                    </Column>
                </DataTable>
                <p v-else-if="q.length >= 3" class="text-muted-color mt-2">Tidak ada pegawai cocok untuk "{{ q }}".</p>
            </div>
        </div>
    </div>

    <!-- Dialog tarik pegawai + pemilihan role -->
    <Dialog v-model:visible="dialogTarik" modal header="Tarik Pegawai" :style="{ width: '28rem' }">
        <div v-if="pegawaiDipilih" class="flex flex-col gap-4">
            <div class="flex flex-col">
                <span class="font-medium text-surface-900 dark:text-surface-0">{{ pegawaiDipilih.nama }}</span>
                <span class="text-muted-color text-sm">{{ pegawaiDipilih.username }}<template v-if="pegawaiDipilih.no_absen"> · {{ pegawaiDipilih.no_absen }}</template></span>
            </div>
            <div class="flex flex-col gap-2">
                <label for="roleTarik" class="font-medium">Peran</label>
                <Select
                    id="roleTarik"
                    v-model="roleTarik"
                    :options="roleOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Pilih peran"
                    class="w-full"
                />
            </div>
        </div>
        <template #footer>
            <Button label="Batal" severity="secondary" text @click="dialogTarik = false" />
            <Button label="Tarik" icon="pi pi-download" :loading="prosesTarik" :disabled="!roleTarik" @click="tarikPegawai" />
        </template>
    </Dialog>
</template>
