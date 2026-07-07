<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineOptions({ layout: AppLayout });
</script>

<template>
    <Head title="Button Components" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 md:col-span-6">
            <div class="card">
                <h5 class="font-semibold text-lg mb-4">Severities</h5>
                <div class="flex flex-wrap gap-3">
                    <Button label="Primary" />
                    <Button label="Secondary" severity="secondary" />
                    <Button label="Success" severity="success" />
                    <Button label="Info" severity="info" />
                    <Button label="Warning" severity="warn" />
                    <Button label="Help" severity="help" />
                    <Button label="Danger" severity="danger" />
                    <Button label="Contrast" severity="contrast" />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card">
                <h5 class="font-semibold text-lg mb-4">Outlined</h5>
                <div class="flex flex-wrap gap-3">
                    <Button label="Primary" outlined />
                    <Button label="Secondary" outlined severity="secondary" />
                    <Button label="Success" outlined severity="success" />
                    <Button label="Danger" outlined severity="danger" />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card">
                <h5 class="font-semibold text-lg mb-4">Sizes</h5>
                <div class="flex flex-wrap items-center gap-3">
                    <Button label="Small" size="small" />
                    <Button label="Normal" />
                    <Button label="Large" size="large" />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card">
                <h5 class="font-semibold text-lg mb-4">Icons</h5>
                <div class="flex flex-wrap gap-3">
                    <Button icon="pi pi-check" />
                    <Button label="Submit" icon="pi pi-check" />
                    <Button label="Submit" icon="pi pi-check" iconPos="right" />
                    <Button icon="pi pi-check" rounded />
                    <Button icon="pi pi-check" rounded outlined />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card">
                <h5 class="font-semibold text-lg mb-4">Loading</h5>
                <div class="flex flex-wrap gap-3">
                    <Button label="Loading" loading />
                    <Button label="Loading" icon="pi pi-check" loading />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card">
                <h5 class="font-semibold text-lg mb-4">Button Group</h5>
                <ButtonGroup>
                    <Button label="Save" icon="pi pi-check" />
                    <Button label="Delete" icon="pi pi-trash" severity="danger" />
                    <Button label="Cancel" icon="pi pi-times" severity="secondary" />
                </ButtonGroup>
            </div>
        </div>
    </div>
</template>
