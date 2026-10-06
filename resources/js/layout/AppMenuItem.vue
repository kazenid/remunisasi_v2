<script setup>
import { useLayout } from '@/layout/composables/layout';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeMount, ref, watch } from 'vue';

const props = defineProps({
    item: {
        type: Object,
        default: () => ({}),
    },
    index: {
        type: Number,
        default: 0,
    },
    root: {
        type: Boolean,
        default: true,
    },
    parentItemKey: {
        type: String,
        default: null,
    },
});

const page = usePage();
const { layoutConfig, layoutState, setActiveMenuItem } = useLayout();

const isActiveMenu = ref(false);
const itemKey = ref(null);

onBeforeMount(() => {
    itemKey.value = props.parentItemKey
        ? props.parentItemKey + '-' + props.index
        : String(props.index);

    const activeItem = layoutState.activeMenuItem;
    isActiveMenu.value = activeItem === itemKey.value || activeItem
        ? activeItem.startsWith(itemKey.value + '-')
        : false;
});

watch(
    () => layoutState.activeMenuItem,
    (newVal) => {
        isActiveMenu.value = newVal === itemKey.value || newVal?.startsWith(itemKey.value + '-') || false;
    },
);

// Check if route is current page
const isCurrentRoute = (routeName) => {
    if (!routeName) return false;
    try {
        return route().current(routeName);
    } catch {
        return false;
    }
};

// Check if any child is active
const hasActiveChild = computed(() => {
    const checkItems = (items) => {
        if (!items) return false;
        return items.some(
            (child) => isCurrentRoute(child.route) || checkItems(child.items),
        );
    };
    return checkItems(props.item.items);
});

const itemClass = computed(() => {
    return [
        {
            'layout-menuitem-active': isActiveMenu.value,
        },
        props.item.class,
    ];
});

const submenuClass = computed(() => ({
    'layout-submenu': true,
    'hidden': !isActiveMenu.value,
}));

function itemClick(event, item) {
    if (item.disabled) {
        event.preventDefault();
        return;
    }

    if (item.url) return; // External link, let browser handle

    if (!item.route && !item.items) return;

    if (item.items) {
        // Toggle submenu
        setActiveMenuItem({
            value: isActiveMenu.value ? null : itemKey.value,
        });
        isActiveMenu.value = !isActiveMenu.value;
    } else {
        // Navigate — close mobile menu
        layoutState.mobileMenuActive = false;
    }
}

// Auto expand parent if child is active
watch(
    () => page.url,
    () => {
        if (hasActiveChild.value && !isActiveMenu.value) {
            isActiveMenu.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <li :class="itemClass">
        <!-- Root label / category header -->
        <template v-if="root && item.items">
            <div class="layout-menuitem-root-text">{{ item.label }}</div>
            <app-menu-item
                v-for="(child, i) in item.items"
                :key="child.label"
                :index="i"
                :item="child"
                :parent-item-key="itemKey"
                :root="false"
            />
        </template>

        <!-- External URL -->
        <a
            v-else-if="item.url"
            :href="item.url"
            :target="item.target"
            :rel="item.target === '_blank' ? 'noopener noreferrer' : undefined"
            class="layout-menuitem-link"
            @click="itemClick($event, item)"
        >
            <i :class="item.icon" class="layout-menuitem-icon"></i>
            <span class="layout-menuitem-text">{{ item.label }}</span>
        </a>

        <!-- Inertia Link (named route) -->
        <Link
            v-else-if="item.route && !item.items"
            :href="route(item.route)"
            :class="['layout-menuitem-link', { 'active-route': isCurrentRoute(item.route) }]"
            @click="itemClick($event, item)"
        >
            <i :class="item.icon" class="layout-menuitem-icon"></i>
            <span class="layout-menuitem-text">{{ item.label }}</span>
        </Link>

        <!-- Group with children -->
        <button
            v-else-if="item.items"
            type="button"
            class="layout-menuitem-link w-full text-left"
            :aria-expanded="isActiveMenu"
            @click="itemClick($event, item)"
        >
            <i :class="item.icon" class="layout-menuitem-icon"></i>
            <span class="layout-menuitem-text">{{ item.label }}</span>
            <i class="layout-menuitem-toggler pi pi-fw pi-angle-down" :class="{ 'rotate-180': isActiveMenu }"></i>
        </button>

        <!-- Submenu (recursive) -->
        <Transition name="layout-submenu">
            <ul v-if="!root && item.items && isActiveMenu" class="layout-submenu" role="menu">
                <app-menu-item
                    v-for="(child, i) in item.items"
                    :key="child.label"
                    :index="i"
                    :item="child"
                    :parent-item-key="itemKey"
                    :root="false"
                />
            </ul>
        </Transition>
    </li>
</template>

<style scoped>
.layout-submenu-enter-active,
.layout-submenu-leave-active {
    transition: max-height 0.2s ease, opacity 0.2s ease;
    overflow: hidden;
    max-height: 800px;
}

.layout-submenu-enter-from,
.layout-submenu-leave-to {
    max-height: 0;
    opacity: 0;
}
</style>
