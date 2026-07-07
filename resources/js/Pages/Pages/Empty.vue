<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });
</script>

<template>
    <Head title="Empty Page" />
    <div class="card">
        <h1 class="text-3xl font-bold text-surface-900 dark:text-surface-0 mb-4">Empty Page</h1>
        <p class="text-muted-color">Use this page as a blank template to start building your new page.</p>
    </div>
</template>
