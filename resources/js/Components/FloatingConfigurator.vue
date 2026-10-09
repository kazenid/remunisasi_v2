<script setup>
import { useLayout } from '@/layout/composables/layout';
import AppConfigurator from '@/layout/AppConfigurator.vue';

const { toggleDarkMode, isDarkTheme } = useLayout();
</script>

<template>
    <div class="fixed flex gap-4 top-8 right-8 z-50">
        <!-- Dark / light mode toggle -->
        <Button
            type="button"
            rounded
            severity="secondary"
            :icon="isDarkTheme ? 'pi pi-moon' : 'pi pi-sun'"
            :aria-label="isDarkTheme ? 'Switch to light mode' : 'Switch to dark mode'"
            @click="toggleDarkMode"
        />

        <!-- Theme configurator -->
        <div class="relative">
            <Button
                icon="pi pi-palette"
                type="button"
                rounded
                aria-label="Open theme configurator"
                v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-slidedown', leaveToClass: 'hidden', hideOnOutsideClick: true }"
            />
            <AppConfigurator />
        </div>
    </div>
</template>
