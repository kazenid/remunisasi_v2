<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: GuestLayout });
</script>

<template>
    <Head title="Access Denied" />
    <FloatingConfigurator />
    <div class="flex flex-col items-center justify-center gap-6">
        <div class="flex items-center justify-center w-24 h-24 rounded-full bg-red-100 dark:bg-red-400/10">
            <i class="pi pi-lock text-red-500 text-4xl"></i>
        </div>
        <div class="text-center">
            <h1 class="text-3xl font-bold text-surface-900 dark:text-surface-0 mb-2">Access Denied</h1>
            <p class="text-muted-color">You don't have permission to view this page.</p>
        </div>
        <Link :href="route('dashboard')">
            <Button label="Go to Dashboard" icon="pi pi-home" />
        </Link>
    </div>
</template>
