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
</script>

<template>
    <div class="card">
        <div class="font-semibold text-xl mb-4">Recent Sales</div>
        <DataTable :value="products" :rows="5" :paginator="true" responsiveLayout="scroll">
            <Column style="width:15%" header="Image">
                <template #body="slotProps">
                    <img
                        :src="`https://primefaces.org/cdn/primevue/images/product/${slotProps.data.image}`"
                        :alt="slotProps.data.image"
                        width="50"
                        class="shadow rounded"
                    />
                </template>
            </Column>
            <Column field="name"  header="Name"  :sortable="true" style="width:35%"></Column>
            <Column field="price" header="Price" :sortable="true" style="width:35%">
                <template #body="slotProps">
                    {{ formatCurrency(slotProps.data.price) }}
                </template>
            </Column>
            <Column style="width:15%" header="View">
                <template #body>
                    <Button icon="pi pi-search" severity="secondary" variant="text" rounded />
                </template>
            </Column>
        </DataTable>
    </div>
</template>
