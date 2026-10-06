<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    katimkers: { type: Object, default: () => ({ data: [] }) },
    filters: { type: Object, default: () => ({}) },
    unitOptions: { type: Array, default: () => [] },
});

const searchQuery = ref(props.filters.search || '');

const showDialog = ref(false);
const showBulkDialog = ref(false);
const editingUser = ref(null);

const candidateOptions = ref([]);
const loadingCandidates = ref(false);
const bulkSearch = ref('');

const form = useForm({
    is_katimker: true,
    atasan_langsung_id: null,
});

const bulkForm = useForm({
    user_ids: [],
    atasan_langsung_id: null,
});

const handleSearch = () => {
    router.get(route('admin.katimker.index'), { search: searchQuery.value }, { preserveState: true });
};

// Ambil kandidat pegawai via fetch agar tidak memicu full page reload.
const loadCandidates = async (search = '', exclude = null) => {
    loadingCandidates.value = true;
    try {
        const params = new URLSearchParams();
        if (search) params.append('search', search);
        if (exclude) params.append('exclude', exclude);
        const res = await fetch(route('admin.katimker.candidates') + '?' + params.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.ok) candidateOptions.value = await res.json();
    } finally {
        loadingCandidates.value = false;
    }
};

const openEdit = (user) => {
    editingUser.value = user;
    form.is_katimker = true;
    form.atasan_langsung_id = user.atasan_langsung_id || null;
    candidateOptions.value = user.atasan_langsung ? [user.atasan_langsung] : [];
    showDialog.value = true;
    loadCandidates('', user.id);
};

const openBulk = () => {
    bulkForm.reset();
    bulkForm.user_ids = [];
    bulkForm.atasan_langsung_id = null;
    bulkSearch.value = '';
    candidateOptions.value = [];
    showBulkDialog.value = true;
    loadCandidates();
};

const submitForm = () => {
    if (!editingUser.value) return;
    form.put(route('admin.katimker.update', editingUser.value.id), {
        onSuccess: () => { showDialog.value = false; },
    });
};

const submitBulk = () => {
    bulkForm.post(route('admin.katimker.bulk-assign'), {
        onSuccess: () => { showBulkDialog.value = false; },
    });
};

const revokeKatimker = (user) => {
    if (!confirm(`Batalkan status Katimker untuk ${user.name}?`)) return;
    router.put(route('admin.katimker.update', user.id), {
        is_katimker: false,
        atasan_langsung_id: null,
    }, { preserveScroll: true });
};

const personLabel = (c) => {
    if (!c) return '-';
    const depan = c.gelar_depan ? `${c.gelar_depan} ` : '';
    const belakang = c.gelar_belakang ? `, ${c.gelar_belakang}` : '';
    const unit = c.nama_unit ? ` - ${c.nama_unit}` : '';
    return `${depan}${c.name}${belakang}${unit}`;
};
</script>

<template>
    <Head title="Manajemen Katimker" />

    <div class="grid grid-cols-12 gap-6">
        <div class="col-span-12">
            <div class="card mb-0">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-surface-900 dark:text-surface-0">Manajemen Katimker</h2>
                        <p class="text-xs text-muted-color mt-1">
                            Tetapkan Ketua Tim Kerja beserta atasan langsung sebagai verifikator kinerjanya.
                        </p>
                    </div>
                    <Button label="Tambah Katimker" icon="pi pi-plus" size="small" @click="openBulk" />
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-6 pt-4 border-t border-surface-200 dark:border-surface-700">
                    <div class="text-xs text-muted-color">
                        Total <strong class="text-surface-900 dark:text-surface-0">{{ katimkers.total || 0 }}</strong> Katimker terdaftar.
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            v-model="searchQuery"
                            @keyup.enter="handleSearch"
                            placeholder="Cari nama, NIP, atau unit..."
                            class="w-64 text-xs p-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800"
                        />
                        <Button icon="pi pi-search" severity="secondary" size="small" @click="handleSearch" />
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12">
            <div class="card p-0 overflow-hidden">
                <DataTable :value="katimkers.data" responsiveLayout="scroll" :paginator="false" class="p-datatable-sm">
                    <template #empty>
                        <div class="text-center py-12 text-muted-color">
                            <i class="pi pi-users text-4xl mb-3 block"></i>
                            Belum ada pegawai yang ditetapkan sebagai Katimker.
                        </div>
                    </template>

                    <Column header="Pegawai" style="width: 240px">
                        <template #body="{ data }">
                            <div class="font-semibold text-xs text-surface-900 dark:text-surface-0">{{ data.name }}</div>
                            <div class="text-[11px] text-muted-color">{{ data.nip || '-' }}</div>
                        </template>
                    </Column>

                    <Column header="Unit" style="width: 180px">
                        <template #body="{ data }">
                            <span class="text-xs">{{ data.nama_unit || '-' }}</span>
                        </template>
                    </Column>

                    <Column header="Atasan Langsung (Verifikator)">
                        <template #body="{ data }">
                            <div v-if="data.atasan_langsung" class="flex items-center gap-2">
                                <i class="pi pi-user text-primary"></i>
                                <span class="text-xs font-medium">{{ personLabel(data.atasan_langsung) }}</span>
                            </div>
                            <Tag v-else value="Belum ditetapkan" severity="danger" />
                        </template>
                    </Column>

                    <Column header="Aksi" style="width: 200px">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <Button label="Ubah Atasan" icon="pi pi-pencil" severity="secondary" size="small" outlined @click="openEdit(data)" />
                                <Button icon="pi pi-times" severity="danger" size="small" outlined title="Batalkan Katimker" @click="revokeKatimker(data)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </div>
        </div>
    </div>

    <!-- Dialog Ubah Atasan Langsung -->
    <Dialog v-model:visible="showDialog" header="Ubah Atasan Langsung" modal :style="{ width: 'min(480px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <form @submit.prevent="submitForm" class="space-y-4 pt-1">
            <div class="p-3 bg-surface-50 dark:bg-surface-800/80 rounded-xl text-xs border border-surface-200 dark:border-surface-700">
                <div class="text-muted-color mb-0.5">Katimker</div>
                <div class="font-semibold text-surface-900 dark:text-surface-0">{{ editingUser?.name }}</div>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Atasan Langsung <span class="text-red-500">*</span></label>
                <select
                    v-model="form.atasan_langsung_id"
                    required
                    class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"
                >
                    <option :value="null" disabled>Pilih atasan langsung</option>
                    <option v-for="c in candidateOptions" :key="c.id" :value="c.id">{{ personLabel(c) }}</option>
                </select>
                <small v-if="form.errors.atasan_langsung_id" class="text-red-500 text-[11px] block mt-1">{{ form.errors.atasan_langsung_id }}</small>
                <small v-if="loadingCandidates" class="text-muted-color text-[11px] block mt-1">Memuat daftar pegawai...</small>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showDialog = false" />
                <Button type="submit" label="Simpan" icon="pi pi-check" :loading="form.processing" />
            </div>
        </form>
    </Dialog>

    <!-- Dialog Penetapan Massal -->
    <Dialog v-model:visible="showBulkDialog" header="Tambah Katimker" modal :style="{ width: 'min(520px, calc(100vw - 32px))' }" :contentStyle="{ maxHeight: 'calc(100dvh - 10rem)', overflowY: 'auto' }">
        <form @submit.prevent="submitBulk" class="space-y-4 pt-1">
            <div class="text-xs text-muted-color leading-relaxed">
                Pilih satu atau beberapa pegawai yang akan ditetapkan sebagai Ketua Tim Kerja, lalu tentukan atasan langsungnya.
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Cari Pegawai</label>
                <input
                    type="text"
                    v-model="bulkSearch"
                    @input="loadCandidates(bulkSearch)"
                    placeholder="Ketik nama atau NIP..."
                    class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Pegawai ({{ bulkForm.user_ids.length }} dipilih)</label>
                <div class="max-h-52 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl divide-y divide-surface-200 dark:divide-surface-700">
                    <label
                        v-for="c in candidateOptions"
                        :key="c.id"
                        class="flex items-center gap-2.5 p-2.5 text-xs cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800/80 transition-colors"
                    >
                        <input type="checkbox" :value="c.id" v-model="bulkForm.user_ids" class="rounded border-surface-300 w-4 h-4 text-primary focus:ring-primary" />
                        <span class="font-medium text-surface-900 dark:text-surface-100">{{ personLabel(c) }}</span>
                        <Tag v-if="c.is_katimker" value="Katimker" severity="info" class="ml-auto" />
                    </label>
                    <div v-if="candidateOptions.length === 0" class="p-4 text-xs text-muted-color text-center">Tidak ada pegawai ditemukan.</div>
                </div>
                <small v-if="bulkForm.errors.user_ids" class="text-red-500 text-[11px] block mt-1">{{ bulkForm.errors.user_ids }}</small>
            </div>

            <div>
                <label class="block text-xs font-semibold mb-1.5">Atasan Langsung <span class="text-red-500">*</span></label>
                <select
                    v-model="bulkForm.atasan_langsung_id"
                    required
                    class="w-full text-xs p-2.5 rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-0 dark:bg-surface-800 focus:outline-none focus:ring-2 focus:ring-primary/20"
                >
                    <option :value="null" disabled>Pilih atasan langsung</option>
                    <option v-for="c in candidateOptions" :key="c.id" :value="c.id">{{ personLabel(c) }}</option>
                </select>
                <small v-if="bulkForm.errors.atasan_langsung_id" class="text-red-500 text-[11px] block mt-1">{{ bulkForm.errors.atasan_langsung_id }}</small>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-surface-200 dark:border-surface-700">
                <Button type="button" label="Batal" severity="secondary" text @click="showBulkDialog = false" />
                <Button type="submit" label="Tetapkan Katimker" icon="pi pi-check" :loading="bulkForm.processing" />
            </div>
        </form>
    </Dialog>


</template>
