<script setup>
import { Link } from '@inertiajs/vue3';

// Pembungkus kartu widget: judul, subjudul, tautan aksi, dan slot isi.
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    actionLabel: { type: String, default: null },
    actionHref: { type: String, default: null },
});
</script>

<template>
    <section class="card mb-0 h-full flex flex-col">
        <header class="flex items-start justify-between gap-3 mb-4">
            <div class="min-w-0">
                <h5 class="m-0 font-semibold text-lg">{{ title }}</h5>
                <p v-if="subtitle" class="m-0 mt-1 text-sm text-muted-color">{{ subtitle }}</p>
            </div>
            <Link v-if="actionHref && actionLabel" :href="actionHref" class="text-primary font-medium text-sm whitespace-nowrap hover:underline">
                {{ actionLabel }} <i class="pi pi-arrow-right text-xs"></i>
            </Link>
        </header>
        <div class="flex-1">
            <slot />
        </div>
    </section>
</template>
