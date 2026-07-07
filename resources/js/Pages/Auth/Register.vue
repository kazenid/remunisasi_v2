<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import FloatingConfigurator from '@/Components/FloatingConfigurator.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineOptions({ layout: GuestLayout });

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Register" />
    <FloatingConfigurator />

    <div class="flex flex-col items-center justify-center">
        <div style="border-radius:56px;padding:0.3rem;background:linear-gradient(180deg,var(--p-primary-color) 10%,rgba(33,150,243,0) 30%)">
            <div class="w-full bg-surface-0 dark:bg-surface-900 py-20 px-8 sm:px-20" style="border-radius:53px">
                <div class="text-center mb-8">
                    <div class="text-surface-900 dark:text-surface-0 text-3xl font-medium mb-4">Create Account</div>
                    <span class="text-muted-color font-medium">Join Sakai today</span>
                </div>

                <form @submit.prevent="submit">
                    <div class="mb-4">
                        <label for="name" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Full Name</label>
                        <InputText id="name" v-model="form.name" class="w-full md:w-[30rem]" :class="{ 'p-invalid': form.errors.name }" placeholder="Your full name" autocomplete="name" />
                        <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
                    </div>
                    <div class="mb-4">
                        <label for="reg-email" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Email</label>
                        <InputText id="reg-email" v-model="form.email" type="email" class="w-full md:w-[30rem]" :class="{ 'p-invalid': form.errors.email }" placeholder="Email address" autocomplete="username" />
                        <small v-if="form.errors.email" class="text-red-500">{{ form.errors.email }}</small>
                    </div>
                    <div class="mb-4">
                        <label for="reg-password" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Password</label>
                        <Password id="reg-password" v-model="form.password" placeholder="Password" :toggleMask="true" fluid :class="{ 'p-invalid': form.errors.password }" autocomplete="new-password" />
                        <small v-if="form.errors.password" class="text-red-500">{{ form.errors.password }}</small>
                    </div>
                    <div class="mb-8">
                        <label for="reg-password-confirm" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Confirm Password</label>
                        <Password id="reg-password-confirm" v-model="form.password_confirmation" placeholder="Confirm password" :toggleMask="true" fluid :feedback="false" autocomplete="new-password" />
                    </div>

                    <Button type="submit" label="Create Account" class="w-full" :loading="form.processing" />
                </form>

                <div class="text-center mt-6 text-muted-color">
                    Already have an account?
                    <Link :href="route('login')" class="text-primary font-medium ml-1 hover:underline">Sign in</Link>
                </div>
            </div>
        </div>
    </div>
</template>
