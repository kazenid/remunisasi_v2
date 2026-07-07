<script setup>
import { useLayout } from '@/layout/composables/layout';
import { computed, ref, watch } from 'vue';

const { isDarkTheme } = useLayout();

const chartData = ref(null);
const chartOptions = ref(null);

function setChart() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--p-text-color');
    const textColorSecondary = documentStyle.getPropertyValue('--p-text-muted-color');
    const surfaceBorder = documentStyle.getPropertyValue('--p-content-border-color');

    chartData.value = {
        labels: ['January', 'February', 'March', 'April', 'May', 'June', 'July'],
        datasets: [
            {
                type: 'bar',
                label: 'Sales',
                backgroundColor: documentStyle.getPropertyValue('--p-primary-400'),
                data: [50, 25, 12, 48, 90, 76, 42],
                barThickness: 32,
            },
            {
                type: 'bar',
                label: 'Revenue',
                backgroundColor: documentStyle.getPropertyValue('--p-primary-300'),
                data: [41, 52, 24, 74, 23, 21, 32],
                barThickness: 32,
            },
            {
                type: 'bar',
                label: 'Expenses',
                backgroundColor: documentStyle.getPropertyValue('--p-primary-200'),
                data: [35, 41, 36, 26, 45, 48, 52],
                barThickness: 32,
            },
        ],
    };

    chartOptions.value = {
        maintainAspectRatio: false,
        aspectRatio: 0.8,
        plugins: {
            tooltip: { mode: 'index', intersect: false },
            legend: { labels: { color: textColor } },
        },
        scales: {
            x: {
                stacked: true,
                ticks: { color: textColorSecondary },
                grid:   { color: surfaceBorder, drawBorder: false },
            },
            y: {
                stacked: true,
                ticks: { color: textColorSecondary },
                grid:   { color: surfaceBorder, drawBorder: false },
            },
        },
    };
}

watch(isDarkTheme, () => setChart(), { immediate: true });
</script>

<template>
    <div class="card">
        <div class="flex justify-between items-start mb-6">
            <div>
                <div class="font-semibold text-xl mb-1">Revenue Streams</div>
                <div class="text-muted-color text-sm">Monthly breakdown</div>
            </div>
        </div>
        <Chart type="bar" :data="chartData" :options="chartOptions" style="height:300px" />
    </div>
</template>
