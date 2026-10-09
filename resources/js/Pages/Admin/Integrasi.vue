<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });

const props = defineProps({
    integrasi: { type: Object, required: true },
});

const up = props.integrasi.status === 'up';

function cekUlang() {
    router.reload({ only: ['integrasi'] });
}
</script>

<template>
    <Head title="Status Integrasi" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 xl:col-span-6">
            <div class="card">
                <div class="flex items-center justify-between mb-6">
                    <h4 class="m-0 font-semibold text-xl">Status Integrasi SIKEP</h4>
                    <Button icon="pi pi-refresh" label="Cek Ulang" size="small" severity="secondary" @click="cekUlang" />
                </div>

                <div class="flex items-center gap-4 mb-6">
                    <div class="flex items-center justify-center rounded-border"
                         :class="up ? 'bg-green-100 dark:bg-green-400/10' : 'bg-red-100 dark:bg-red-400/10'"
                         style="width:3.5rem;height:3.5rem">
                        <i :class="up ? 'pi pi-check-circle text-green-500' : 'pi pi-times-circle text-red-500'" class="text-2xl!"></i>
                    </div>
                    <div>
                        <div class="text-surface-900 dark:text-surface-0 font-medium text-xl">
                            {{ up ? 'Terhubung' : 'Tidak Terhubung' }}
                        </div>
                        <span class="text-muted-color">{{ up ? 'Basis data SIKEP dapat diakses.' : integrasi.pesan }}</span>
                    </div>
                </div>

                <dl class="grid grid-cols-12 gap-y-3">
                    <dt class="col-span-5 text-muted-color">Jumlah pegawai terbaca</dt>
                    <dd class="col-span-7 font-medium">{{ integrasi.jumlah_pegawai ?? '—' }}</dd>

                    <dt class="col-span-5 text-muted-color">Host</dt>
                    <dd class="col-span-7">{{ integrasi.host }}:{{ integrasi.port }}</dd>

                    <dt class="col-span-5 text-muted-color">Database</dt>
                    <dd class="col-span-7">{{ integrasi.database }}</dd>

                    <dt class="col-span-5 text-muted-color">Dicek pada</dt>
                    <dd class="col-span-7">{{ integrasi.dicek_pada }}</dd>
                </dl>

                <Message v-if="!up" severity="warn" :closable="false" class="mt-6">
                    Operasi yang bergantung pada SIKEP (login, pemetaan, nama pegawai) dapat terganggu. Operasi data lokal tetap berjalan.
                </Message>
            </div>
        </div>
    </div>
</template>
