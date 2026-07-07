<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const value1 = ref('');
const value2 = ref('');
const value3 = ref('');
const value4 = ref('');
const value5 = ref(null);
const value6 = ref(null);
const value7 = ref(null);
const checked1 = ref(false);
const checked2 = ref(false);
const radioValue = ref(null);
const selectedCity = ref(null);

const cities = ref([
    { name: 'New York',  code: 'NY' },
    { name: 'Rome',      code: 'RM' },
    { name: 'London',    code: 'LDN' },
    { name: 'Istanbul',  code: 'IST' },
    { name: 'Paris',     code: 'PRS' },
]);
</script>

<template>
    <Head title="Input Components" />

    <div class="grid grid-cols-12 gap-8">
        <div class="col-span-12 md:col-span-6">
            <div class="card p-fluid">
                <h5 class="font-semibold text-lg mb-4">InputText</h5>
                <div class="flex flex-col gap-4">
                    <InputText v-model="value1" type="text" placeholder="Default" />
                    <InputText v-model="value2" type="text" placeholder="Disabled" disabled />
                    <InputText v-model="value3" type="text" placeholder="Invalid" invalid />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card p-fluid">
                <h5 class="font-semibold text-lg mb-4">Checkbox &amp; Radio</h5>
                <div class="flex flex-col gap-4">
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="checked1" inputId="cb1" binary />
                        <label for="cb1">Checkbox 1</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="checked2" inputId="cb2" binary />
                        <label for="cb2">Checkbox 2</label>
                    </div>
                    <Divider />
                    <div class="flex items-center gap-2">
                        <RadioButton v-model="radioValue" inputId="rb1" name="city" value="Chicago" />
                        <label for="rb1">Chicago</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <RadioButton v-model="radioValue" inputId="rb2" name="city" value="Los Angeles" />
                        <label for="rb2">Los Angeles</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card p-fluid">
                <h5 class="font-semibold text-lg mb-4">Select &amp; MultiSelect</h5>
                <div class="flex flex-col gap-4">
                    <Select v-model="selectedCity" :options="cities" optionLabel="name" placeholder="Select a City" />
                    <MultiSelect v-model="value5" :options="cities" optionLabel="name" placeholder="Select Cities" :maxSelectedLabels="3" />
                </div>
            </div>
        </div>

        <div class="col-span-12 md:col-span-6">
            <div class="card p-fluid">
                <h5 class="font-semibold text-lg mb-4">InputNumber &amp; Textarea</h5>
                <div class="flex flex-col gap-4">
                    <InputNumber v-model="value6" placeholder="Integer" />
                    <InputNumber v-model="value7" mode="currency" currency="USD" locale="en-US" />
                    <Textarea v-model="value4" rows="4" placeholder="Your message..." />
                </div>
            </div>
        </div>
    </div>
</template>
