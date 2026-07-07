<script setup>
defineProps({
    products: {
        type: Array,
        default: () => [],
    },
});

function formatCurrency(value) {
    return value.toLocaleString('en-US', { style: 'currency', currency: 'USD' });
}

const statusSeverity = (status) => {
    const map = {
        INSTOCK:    'success',
        LOWSTOCK:   'warn',
        OUTOFSTOCK: 'danger',
    };
    return map[status] ?? 'info';
};
</script>

<template>
    <div class="card">
        <div class="flex justify-between items-center mb-6">
            <div class="font-semibold text-xl">Best Selling Products</div>
            <div>
                <Button icon="pi pi-ellipsis-v" severity="secondary" variant="text" rounded />
            </div>
        </div>
        <ul class="list-none p-0 m-0 flex flex-col gap-4">
            <li v-for="product in products.slice(0, 5)" :key="product.id" class="flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="w-16 h-16 shrink-0 rounded-lg overflow-hidden">
                    <img
                        :src="`https://primefaces.org/cdn/primevue/images/product/${product.image}`"
                        :alt="product.name"
                        class="w-full h-full object-cover"
                    />
                </div>
                <div class="flex-1 flex flex-col gap-1">
                    <span class="font-medium text-surface-900 dark:text-surface-0">{{ product.name }}</span>
                    <div class="flex items-center gap-2">
                        <Rating :modelValue="product.rating" readonly :cancel="false" :stars="5" />
                        <span class="text-muted-color text-sm">{{ product.rating }}</span>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-1">
                    <span class="font-semibold text-surface-900 dark:text-surface-0">{{ formatCurrency(product.price) }}</span>
                    <Tag :value="product.inventoryStatus" :severity="statusSeverity(product.inventoryStatus)" />
                </div>
            </li>
        </ul>
    </div>
</template>
