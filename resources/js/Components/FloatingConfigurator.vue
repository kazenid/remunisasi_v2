<script setup>
import { useLayout } from '@/layout/composables/layout';
import AppConfigurator from '@/layout/AppConfigurator.vue';

const { toggleDarkMode, isDarkTheme } = useLayout();
</script>

<template>
    <div class="fixed bottom-6 right-6 z-50 flex flex-col items-end gap-3">
        <!-- Dark mode toggle -->
        <button
            type="button"
            class="layout-config-button"
            @click="toggleDarkMode"
            :aria-label="isDarkTheme ? 'Switch to light mode' : 'Switch to dark mode'"
        >
            <i :class="['pi', isDarkTheme ? 'pi-sun' : 'pi-moon']"></i>
        </button>

        <!-- Theme configurator -->
        <div class="relative">
            <button
                v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-fadein', leaveToClass: 'hidden', hideOnOutsideClick: true }"
                type="button"
                class="layout-config-button"
                aria-label="Open theme configurator"
            >
                <i class="pi pi-palette"></i>
            </button>
            <div class="hidden absolute bottom-14 right-0 w-64">
                <AppConfigurator />
            </div>
        </div>
    </div>
</template>
