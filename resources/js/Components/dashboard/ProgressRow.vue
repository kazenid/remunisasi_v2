<script setup>
import { computed } from 'vue';

// Baris capaian: label, nilai/target, dan bilah progres. Warna memakai token PrimeVue.
const props = defineProps({
    label: { type: String, required: true },
    detail: { type: String, default: null },
    percent: { type: Number, default: null }, // null → target belum diatur
    color: { type: String, default: 'primary' },
});

const warnaBar = computed(() => `var(--p-${props.color}-${props.color === 'primary' ? 'color' : '500'})`);
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-3 mb-2">
            <span class="font-medium text-surface-900 dark:text-surface-0">{{ label }}</span>
            <span class="text-sm text-muted-color">
                <template v-if="percent !== null"><span class="font-semibold text-surface-900 dark:text-surface-0">{{ percent }}%</span> · </template>
                {{ detail }}
            </span>
        </div>
        <ProgressBar
            :value="percent ?? 0"
            :showValue="false"
            style="height:0.5rem"
            :pt="{ value: { style: { background: warnaBar } } }"
        />
    </div>
</template>
