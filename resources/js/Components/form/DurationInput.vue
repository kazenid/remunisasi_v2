<script setup>
import { computed } from 'vue';

// Input durasi jam + menit. Mengekspos v-model:jam dan v-model:menit (integer).
const props = defineProps({
    jam: { type: Number, default: 0 },
    menit: { type: Number, default: 0 },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:jam', 'update:menit']);

const jamModel = computed({
    get: () => props.jam,
    set: (v) => emit('update:jam', v ?? 0),
});
const menitModel = computed({
    get: () => props.menit,
    set: (v) => emit('update:menit', v ?? 0),
});

const totalMenit = computed(() => (props.jam ?? 0) * 60 + (props.menit ?? 0));
</script>

<template>
    <div class="flex items-center gap-3">
        <div class="flex items-center gap-2">
            <InputNumber v-model="jamModel" :min="0" :max="24" showButtons buttonLayout="horizontal" :inputStyle="{ width: '3.5rem' }" :class="{ 'p-invalid': invalid }">
                <template #incrementbuttonicon><i class="pi pi-plus" /></template>
                <template #decrementbuttonicon><i class="pi pi-minus" /></template>
            </InputNumber>
            <span class="text-muted-color">jam</span>
        </div>
        <div class="flex items-center gap-2">
            <InputNumber v-model="menitModel" :min="0" :max="59" showButtons buttonLayout="horizontal" :inputStyle="{ width: '3.5rem' }" :class="{ 'p-invalid': invalid }">
                <template #incrementbuttonicon><i class="pi pi-plus" /></template>
                <template #decrementbuttonicon><i class="pi pi-minus" /></template>
            </InputNumber>
            <span class="text-muted-color">menit</span>
        </div>
        <span v-if="totalMenit > 0" class="text-sm text-muted-color">= {{ totalMenit }} menit</span>
    </div>
</template>
