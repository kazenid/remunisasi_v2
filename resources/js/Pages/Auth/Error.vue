<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: GuestLayout });
</script>

<template>
    <Head title="Error" />
    <FloatingConfigurator />
    <div class="flex flex-col items-center justify-center gap-6">
        <div class="flex items-center justify-center w-24 h-24 rounded-full bg-orange-100 dark:bg-orange-400/10">
            <i class="pi pi-exclamation-triangle text-orange-500 text-4xl"></i>
        </div>
        <div class="text-center">
            <h1 class="text-3xl font-bold text-surface-900 dark:text-surface-0 mb-2">Something Went Wrong</h1>
            <p class="text-muted-color">An unexpected error occurred. Please try again later.</p>
        </div>
        <Link :href="route('dashboard')">
            <Button label="Go to Dashboard" icon="pi pi-home" />
        </Link>
    </div>
</template>
