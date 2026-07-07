<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { ref, computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    products: {
        type: Array,
        default: () => [],
    },
});

const toast = useToast();
const confirm = useConfirm();

// Dialog state
const productDialog = ref(false);
const deleteProductDialog = ref(false);
const selectedProducts = ref([]);
const filters = ref({
    global: { value: null, matchMode: 'contains' },
});
const submitted = ref(false);
const isEditing = ref(false);

const statuses = [
    { label: 'In Stock',    value: 'INSTOCK' },
    { label: 'Low Stock',   value: 'LOWSTOCK' },
    { label: 'Out of Stock', value: 'OUTOFSTOCK' },
];

const form = useForm({
    id: null,
    name: '',
    description: '',
    price: 0,
    category: '',
    quantity: 0,
    inventory_status: 'INSTOCK',
    rating: 0,
});

function openNew() {
    form.reset();
    submitted.value = false;
    isEditing.value = false;
    productDialog.value = true;
}

function editProduct(product) {
    form.id = product.id;
    form.name = product.name;
    form.description = product.description;
    form.price = product.price;
    form.category = product.category;
    form.quantity = product.quantity;
    form.inventory_status = product.inventory_status;
    form.rating = product.rating;
    isEditing.value = true;
    submitted.value = false;
    productDialog.value = true;
}

function hideDialog() {
    productDialog.value = false;
    submitted.value = false;
}

function saveProduct() {
    submitted.value = true;
    if (!form.name.trim()) return;

    if (isEditing.value) {
        form.put(route('products.update', form.id), {
            onSuccess: () => {
                productDialog.value = false;
                toast.add({ severity: 'success', summary: 'Success', detail: 'Product updated', life: 3000 });
            },
        });
    } else {
        form.post(route('products.store'), {
            onSuccess: () => {
                productDialog.value = false;
                toast.add({ severity: 'success', summary: 'Success', detail: 'Product created', life: 3000 });
            },
        });
    }
}

function confirmDeleteProduct(product) {
    form.id = product.id;
    deleteProductDialog.value = true;
}

function deleteProduct() {
    router.delete(route('products.destroy', form.id), {
        onSuccess: () => {
            deleteProductDialog.value = false;
            toast.add({ severity: 'success', summary: 'Success', detail: 'Product deleted', life: 3000 });
        },
    });
}

function getSeverity(status) {
    const map = { INSTOCK: 'success', LOWSTOCK: 'warn', OUTOFSTOCK: 'danger' };
    return map[status] ?? 'info';
}

function formatCurrency(value) {
    return Number(value).toLocaleString('en-US', { style: 'currency', currency: 'USD' });
}
</script>

<template>
    <Head title="Products CRUD" />

    <div class="card">
        <Toolbar class="mb-6">
            <template #start>
                <Button label="New" icon="pi pi-plus" severity="success" class="mr-2" @click="openNew" />
                <Button label="Delete" icon="pi pi-trash" severity="danger" :disabled="!selectedProducts.length" />
            </template>
            <template #end>
                <Button label="Export" icon="pi pi-upload" severity="secondary" />
            </template>
        </Toolbar>

        <DataTable
            v-model:selection="selectedProducts"
            :value="products"
            dataKey="id"
            :paginator="true"
            :rows="10"
            :filters="filters"
            filterDisplay="menu"
            :globalFilterFields="['name', 'category', 'inventoryStatus']"
            responsiveLayout="scroll"
            stripedRows
        >
            <template #header>
                <div class="flex justify-between items-center">
                    <h4 class="m-0 font-semibold text-xl">Manage Products</h4>
                    <IconField>
                        <InputIcon><i class="pi pi-search" /></InputIcon>
                        <InputText v-model="filters['global'].value" placeholder="Search..." />
                    </IconField>
                </div>
            </template>

            <Column selectionMode="multiple" style="width:3rem" :exportable="false" />
            <Column field="name" header="Name" sortable style="min-width:16rem" />
            <Column field="price" header="Price" sortable style="min-width:8rem">
                <template #body="{ data }">{{ formatCurrency(data.price) }}</template>
            </Column>
            <Column field="category" header="Category" sortable style="min-width:10rem" />
            <Column field="rating" header="Reviews" sortable style="min-width:10rem">
                <template #body="{ data }">
                    <Rating :modelValue="data.rating" readonly :cancel="false" />
                </template>
            </Column>
            <Column field="inventory_status" header="Status" sortable style="min-width:12rem">
                <template #body="{ data }">
                    <Tag :value="data.inventory_status" :severity="getSeverity(data.inventory_status)" />
                </template>
            </Column>
            <Column :exportable="false" style="min-width:8rem">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" outlined rounded class="mr-2" @click="editProduct(data)" />
                    <Button icon="pi pi-trash" outlined rounded severity="danger" @click="confirmDeleteProduct(data)" />
                </template>
            </Column>
        </DataTable>
    </div>

    <!-- Create / Edit Dialog -->
    <Dialog v-model:visible="productDialog" :style="{ width: '450px' }" header="Product Details" :modal="true">
        <div class="flex flex-col gap-4">
            <div>
                <label for="p-name" class="block font-bold mb-2">Name <span class="text-red-500">*</span></label>
                <InputText id="p-name" v-model.trim="form.name" class="w-full" :class="{ 'p-invalid': submitted && !form.name }" />
                <small v-if="submitted && !form.name" class="text-red-500">Name is required.</small>
                <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
            </div>
            <div>
                <label for="p-description" class="block font-bold mb-2">Description</label>
                <Textarea id="p-description" v-model="form.description" rows="3" class="w-full" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="p-price" class="block font-bold mb-2">Price</label>
                    <InputNumber id="p-price" v-model="form.price" mode="currency" currency="USD" locale="en-US" />
                </div>
                <div>
                    <label for="p-qty" class="block font-bold mb-2">Quantity</label>
                    <InputNumber id="p-qty" v-model="form.quantity" :min="0" />
                </div>
            </div>
            <div>
                <label for="p-category" class="block font-bold mb-2">Category</label>
                <InputText id="p-category" v-model="form.category" class="w-full" />
            </div>
            <div>
                <label class="block font-bold mb-2">Inventory Status</label>
                <Select v-model="form.inventory_status" :options="statuses" optionLabel="label" optionValue="value" class="w-full" />
            </div>
        </div>
        <template #footer>
            <Button label="Cancel" icon="pi pi-times" text @click="hideDialog" />
            <Button label="Save" icon="pi pi-check" @click="saveProduct" :loading="form.processing" />
        </template>
    </Dialog>

    <!-- Delete Confirm Dialog -->
    <Dialog v-model:visible="deleteProductDialog" :style="{ width: '450px' }" header="Confirm Delete" :modal="true">
        <div class="flex items-center gap-4">
            <i class="pi pi-exclamation-triangle text-orange-500 text-4xl"></i>
            <span>Are you sure you want to delete this product?</span>
        </div>
        <template #footer>
            <Button label="No" icon="pi pi-times" text @click="deleteProductDialog = false" />
            <Button label="Yes" icon="pi pi-check" severity="danger" @click="deleteProduct" />
        </template>
    </Dialog>
</template>
