<script setup>
import { useLayout } from '@/layout/composables/layout';
import { labelBulanTren } from '@/lib/format';
import { ref, watch } from 'vue';

// Grafik batang tren bulanan. Warna dibaca dari token PrimeVue (--p-*), jadi ikut
// preset/warna primer yang dipilih di configurator dan mode gelap.
const props = defineProps({
    labels: { type: Array, required: true }, // ["2026-05", ...]
    // [{ label, data, color }] — color: nama palet PrimeVue ('primary','green','red','orange','blue') atau 'primary'
    datasets: { type: Array, required: true },
    stacked: { type: Boolean, default: false },
    unit: { type: String, default: '' },
    height: { type: String, default: '280px' },
});

const { isDarkTheme } = useLayout();
const chartData = ref(null);
const chartOptions = ref(null);

function token(style, warna) {
    return warna === 'primary'
        ? style.getPropertyValue('--p-primary-400')
        : style.getPropertyValue(`--p-${warna}-500`);
}

function susun() {
    const style = getComputedStyle(document.documentElement);
    const teks = style.getPropertyValue('--p-text-color');
    const teksSamar = style.getPropertyValue('--p-text-muted-color');
    const garis = style.getPropertyValue('--p-content-border-color');

    chartData.value = {
        labels: props.labels.map(labelBulanTren),
        datasets: props.datasets.map((d) => ({
            type: 'bar',
            label: d.label,
            data: d.data,
            backgroundColor: token(style, d.color ?? 'primary'),
            borderRadius: 4,
            maxBarThickness: 36,
        })),
    };

    chartOptions.value = {
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { color: teks, usePointStyle: true, boxWidth: 8 } },
            tooltip: {
                mode: 'index',
                intersect: false,
                callbacks: { label: (c) => ` ${c.dataset.label}: ${c.parsed.y}${props.unit ? ' ' + props.unit : ''}` },
            },
        },
        scales: {
            x: { stacked: props.stacked, ticks: { color: teksSamar }, grid: { display: false } },
            y: { stacked: props.stacked, beginAtZero: true, ticks: { color: teksSamar, precision: 0 }, grid: { color: garis } },
        },
    };
}

watch([isDarkTheme, () => props.labels, () => props.datasets], susun, { immediate: true, deep: true });
</script>

<template>
    <Chart type="bar" :data="chartData" :options="chartOptions" :style="{ height }" />
</template>
