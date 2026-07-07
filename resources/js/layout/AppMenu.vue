<script setup>
import { ref } from 'vue';
import AppMenuItem from './AppMenuItem.vue';

const model = ref([
    {
        label: 'Home',
        items: [
            {
                label: 'Dashboard',
                icon: 'pi pi-fw pi-home',
                route: 'dashboard',
            },
        ],
    },
    {
        label: 'UI Components',
        items: [
            { label: 'Form Layout',  icon: 'pi pi-fw pi-id-card',      route: 'uikit.formlayout' },
            { label: 'Input',        icon: 'pi pi-fw pi-check-square',  route: 'uikit.input' },
            { label: 'Button',       icon: 'pi pi-fw pi-mobile',        route: 'uikit.button',   class: 'rotated-icon' },
            { label: 'Table',        icon: 'pi pi-fw pi-table',         route: 'uikit.table' },
            { label: 'List',         icon: 'pi pi-fw pi-list',          route: 'uikit.list' },
            { label: 'Tree',         icon: 'pi pi-fw pi-share-alt',     route: 'uikit.tree' },
            { label: 'Panel',        icon: 'pi pi-fw pi-tablet',        route: 'uikit.panel' },
            { label: 'Overlay',      icon: 'pi pi-fw pi-clone',         route: 'uikit.overlay' },
            { label: 'Media',        icon: 'pi pi-fw pi-image',         route: 'uikit.media' },
            { label: 'Menu',         icon: 'pi pi-fw pi-bars',          route: 'uikit.menu' },
            { label: 'Message',      icon: 'pi pi-fw pi-comment',       route: 'uikit.message' },
            { label: 'File',         icon: 'pi pi-fw pi-file',          route: 'uikit.file' },
            { label: 'Chart',        icon: 'pi pi-fw pi-chart-bar',     route: 'uikit.charts' },
            { label: 'Timeline',     icon: 'pi pi-fw pi-calendar',      route: 'uikit.timeline' },
            { label: 'Misc',         icon: 'pi pi-fw pi-circle',        route: 'uikit.misc' },
        ],
    },
    {
        label: 'Prime Blocks',
        icon: 'pi pi-fw pi-prime',
        items: [
            { label: 'Free Blocks', icon: 'pi pi-fw pi-eye',   route: 'blocks.free' },
            { label: 'All Blocks',  icon: 'pi pi-fw pi-globe', url: 'https://blocks.primevue.org/', target: '_blank' },
        ],
    },
    {
        label: 'Pages',
        icon: 'pi pi-fw pi-briefcase',
        items: [
            {
                label: 'Auth',
                icon: 'pi pi-fw pi-user',
                items: [
                    { label: 'Login',         icon: 'pi pi-fw pi-sign-in',       route: 'login' },
                    { label: 'Register',      icon: 'pi pi-fw pi-user-plus',      route: 'register' },
                    { label: 'Error',         icon: 'pi pi-fw pi-times-circle',   route: 'auth.error' },
                    { label: 'Access Denied', icon: 'pi pi-fw pi-lock',           route: 'auth.access' },
                ],
            },
            { label: 'Crud',      icon: 'pi pi-fw pi-pencil',            route: 'pages.crud' },
            { label: 'Not Found', icon: 'pi pi-fw pi-exclamation-circle', route: 'pages.notfound' },
            { label: 'Empty',     icon: 'pi pi-fw pi-circle-off',         route: 'pages.empty' },
        ],
    },
    {
        label: 'Hierarchy',
        icon: 'pi pi-fw pi-align-left',
        items: [
            {
                label: 'Submenu 1',
                icon: 'pi pi-fw pi-align-left',
                items: [
                    {
                        label: 'Submenu 1.1',
                        icon: 'pi pi-fw pi-align-left',
                        items: [
                            { label: 'Submenu 1.1.1', icon: 'pi pi-fw pi-align-left' },
                            { label: 'Submenu 1.1.2', icon: 'pi pi-fw pi-align-left' },
                            { label: 'Submenu 1.1.3', icon: 'pi pi-fw pi-align-left' },
                        ],
                    },
                    {
                        label: 'Submenu 1.2',
                        icon: 'pi pi-fw pi-align-left',
                        items: [
                            { label: 'Submenu 1.2.1', icon: 'pi pi-fw pi-align-left' },
                        ],
                    },
                ],
            },
            {
                label: 'Submenu 2',
                icon: 'pi pi-fw pi-align-left',
                items: [
                    {
                        label: 'Submenu 2.1',
                        icon: 'pi pi-fw pi-align-left',
                        items: [
                            { label: 'Submenu 2.1.1', icon: 'pi pi-fw pi-align-left' },
                            { label: 'Submenu 2.1.2', icon: 'pi pi-fw pi-align-left' },
                        ],
                    },
                    {
                        label: 'Submenu 2.2',
                        icon: 'pi pi-fw pi-align-left',
                        items: [
                            { label: 'Submenu 2.2.1', icon: 'pi pi-fw pi-align-left' },
                        ],
                    },
                ],
            },
        ],
    },
    {
        label: 'Get Started',
        items: [
            { label: 'Documentation', icon: 'pi pi-fw pi-book',   route: 'documentation' },
            { label: 'View Source',   icon: 'pi pi-fw pi-github', url: 'https://github.com/primefaces/sakai-vue', target: '_blank' },
        ],
    },
]);
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in model" :key="i">
            <app-menu-item v-if="!item.separator" :item="item" :index="i" />
            <li v-if="item.separator" class="menu-separator" role="separator"></li>
        </template>
    </ul>
</template>
