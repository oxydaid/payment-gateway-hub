<script setup lang="ts">
import { onMounted, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Lock, Mail, Loader2, Shield } from '@lucide/vue';

const page = usePage();
const settings = computed(() => page.props.app_settings);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

onMounted(() => {
    // Apply dynamic branding colors
    if (settings.value?.primary_color) {
        document.documentElement.style.setProperty('--primary', settings.value.primary_color);
        document.documentElement.style.setProperty('--ring', settings.value.primary_color);
    }
    if (settings.value?.secondary_color) {
        document.documentElement.style.setProperty('--secondary', settings.value.secondary_color);
    }
    
    // Ensure the theme is set correctly from local storage
    const theme = localStorage.getItem('theme') || 'dark';
    if (theme === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
});
</script>

<template>
    <Head title="Sign In" />

    <div class="flex min-h-screen items-center justify-center bg-background text-foreground px-4 py-12 font-sans selection:bg-primary selection:text-black transition-colors duration-200">
        <div class="w-full max-w-md">
            <!-- Branding Header -->
            <div class="mb-8 text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary mb-3">
                    <img 
                        v-if="settings?.logo_url" 
                        :src="settings.logo_url" 
                        :alt="settings.app_name" 
                        class="h-8 w-8 object-contain"
                    />
                    <Shield v-else class="h-6 w-6" />
                </div>
                <h2 class="text-3xl font-extrabold tracking-tight text-foreground">
                    {{ settings?.app_name || 'Payment Bridge' }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Access the Super Admin Control Panel
                </p>
            </div>

            <!-- Card Container -->
            <Card class="border-border bg-card shadow-2xl">
                <CardHeader class="space-y-1 border-b border-border pb-4">
                    <CardTitle class="text-xl text-foreground">Sign in to your account</CardTitle>
                    <CardDescription class="text-sm text-muted-foreground">
                        Enter your administrator credentials below
                    </CardDescription>
                </CardHeader>
                <CardContent class="pt-6">
                    <form @submit.prevent="submit" class="space-y-4">
                        <!-- Email Input -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Email Address</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                    <Mail class="h-4 w-4" />
                                </span>
                                <Input
                                    v-model="form.email"
                                    type="email"
                                    placeholder="admin@example.com"
                                    required
                                    autofocus
                                    autocomplete="username"
                                    class="pl-10 bg-muted border-none text-foreground placeholder:text-muted-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                            </div>
                            <p v-if="form.errors.email" class="text-xs text-destructive mt-1">{{ form.errors.email }}</p>
                        </div>

                        <!-- Password Input -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Password</label>
                            </div>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                                    <Lock class="h-4 w-4" />
                                </span>
                                <Input
                                    v-model="form.password"
                                    type="password"
                                    placeholder="••••••••"
                                    required
                                    autocomplete="current-password"
                                    class="pl-10 bg-muted border-none text-foreground placeholder:text-muted-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                            </div>
                            <p v-if="form.errors.password" class="text-xs text-destructive mt-1">{{ form.errors.password }}</p>
                        </div>

                        <!-- Remember Me Toggle -->
                        <div class="flex items-center space-x-2 pt-1">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                id="remember"
                                class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary cursor-pointer"
                            />
                            <label for="remember" class="text-sm font-medium text-muted-foreground cursor-pointer select-none">
                                Remember this device
                            </label>
                        </div>

                        <!-- Submit Button -->
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            class="w-full bg-primary hover:opacity-90 text-black font-bold uppercase tracking-wider h-11 transition-all rounded-full hover:scale-[1.02] active:scale-[0.98] cursor-pointer"
                        >
                            <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span v-else>Sign In</span>
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <div class="mt-8 text-center text-xs text-muted-foreground/60">
                &copy; 2026 {{ settings?.app_name || 'Payment Bridge' }}. All rights reserved.
            </div>
        </div>
    </div>
</template>
