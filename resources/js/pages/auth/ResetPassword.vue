<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { onMounted } from 'vue';

const props = defineProps<{
    token: string;
    email: string;
}>();

// Reactive form state
const form = useForm({
    email: '',
    token: '',
    password: '',
    password_confirmation: '',
});

// Initialize form with provided props
onMounted(() => {
    form.email = props.email || '';
    form.token = props.token || '';
});

// Submit handler
const submit = () => {
    form.post('/reset-password', {
        onSuccess: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthLayout
        title="Reset password"
        description="Please enter your new password below"
    >
        <Head title="Reset password" />

        <form @submit.prevent="submit" class="grid gap-6">
            <!-- Email (readonly but still submitted) -->
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    v-model="form.email"
                    autocomplete="email"
                    readonly
                    class="mt-1 block w-full"
                />
                <InputError :message="form.errors.email" class="mt-2" />
            </div>

            <!-- New Password -->
            <div class="grid gap-2">
                <Label for="password">New Password</Label>
                <Input
                    id="password"
                    type="password"
                    name="password"
                    v-model="form.password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Password"
                />
                <InputError :message="form.errors.password" />
            </div>

            <!-- Confirm Password -->
            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm Password</Label>
                <Input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    v-model="form.password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Confirm password"
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <!-- Submit Button -->
            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="form.processing"
                data-test="reset-password-button"
            >
                <LoaderCircle
                    v-if="form.processing"
                    class="h-4 w-4 animate-spin"
                />
                Reset password
            </Button>
        </form>
    </AuthLayout>
</template>
