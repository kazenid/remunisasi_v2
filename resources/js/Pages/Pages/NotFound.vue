<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineOptions({ layout: GuestLayout });
</script>

<template>
    <Head title="404 Not Found" />
    <div class="flex flex-col items-center justify-center gap-6">
        <div class="text-center">
            <div class="text-8xl font-black text-primary mb-4">404</div>
            <h1 class="text-3xl font-bold text-surface-900 dark:text-surface-0 mb-2">Page Not Found</h1>
            <p class="text-muted-color max-w-sm">The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.</p>
        </div>
        <Link :href="route('dashboard')">
            <Button label="Go to Dashboard" icon="pi pi-home" size="large" />
        </Link>
    </div>
</template>
