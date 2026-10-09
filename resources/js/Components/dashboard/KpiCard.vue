<script setup>
import { Link } from '@inertiajs/vue3';

// Kartu angka utama. Kelas warna ditulis literal (Tailwind JIT tidak memindai
// string yang dirangkai dinamis) dan mengikuti pola StatsWidget bawaan Sakai.
const warna = {
    blue: { bg: 'bg-blue-100 dark:bg-blue-400/10', ikon: 'text-blue-500' },
    green: { bg: 'bg-green-100 dark:bg-green-400/10', ikon: 'text-green-500' },
    red: { bg: 'bg-red-100 dark:bg-red-400/10', ikon: 'text-red-500' },
    orange: { bg: 'bg-orange-100 dark:bg-orange-400/10', ikon: 'text-orange-500' },
    cyan: { bg: 'bg-cyan-100 dark:bg-cyan-400/10', ikon: 'text-cyan-500' },
    purple: { bg: 'bg-purple-100 dark:bg-purple-400/10', ikon: 'text-purple-500' },
    surface: { bg: 'bg-surface-100 dark:bg-surface-700', ikon: 'text-surface-500' },
};

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    icon: { type: String, required: true },
    color: { type: String, default: 'blue' },
    hint: { type: String, default: null },
    // Teks hint diberi warna aksen bila true (mis. ada yang perlu perhatian).
    alert: { type: Boolean, default: false },
    href: { type: String, default: null },
});
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="card mb-0 block h-full no-underline"
        :class="href ? 'transition-shadow hover:shadow-md focus-visible:outline-2 focus-visible:outline-primary' : ''"
    >
        <div class="flex justify-between gap-3">
            <div class="min-w-0">
                <span class="block text-muted-color font-medium mb-3">{{ label }}</span>
                <div class="text-surface-900 dark:text-surface-0 font-semibold text-2xl leading-tight">{{ value }}</div>
            </div>
            <div class="flex items-center justify-center rounded-border shrink-0" :class="warna[color]?.bg" style="width:2.5rem;height:2.5rem">
                <i :class="[icon, warna[color]?.ikon, 'text-xl!']"></i>
            </div>
        </div>
        <div v-if="hint" class="mt-3 text-sm" :class="alert ? 'text-orange-500 font-medium' : 'text-muted-color'">{{ hint }}</div>
    </component>
</template>
