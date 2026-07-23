<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Save, User, Key, Loader2 } from '@lucide/vue';

interface UserInfo {
    name: string;
    email: string;
}

const props = defineProps<{
    user: UserInfo;
}>();

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
});

const passwordForm = useForm({
    name: props.user.name,
    email: props.user.email,
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitProfile() {
    profileForm.post('/profile', {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.name = profileForm.name;
            passwordForm.email = profileForm.email;
        }
    });
}

function submitPassword() {
    passwordForm.post('/profile', {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset('current_password', 'password', 'password_confirmation');
        }
    });
}
</script>

<template>
    <Head title="Profile Settings" />

    <AdminLayout title="Profile Settings">
        <div class="space-y-6 max-w-4xl">
            <!-- Profile Info Card -->
            <Card class="bg-card border-border shadow-xl">
                <CardHeader class="border-b border-border py-4">
                    <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                        <User class="h-4 w-4 text-primary" />
                        Profile Information
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-6">
                    <form @submit.prevent="submitProfile" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <!-- Name -->
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Full Name</label>
                                <Input
                                    v-model="profileForm.name"
                                    type="text"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                                <p v-if="profileForm.errors.name" class="text-xs text-destructive mt-1">{{ profileForm.errors.name }}</p>
                            </div>

                            <!-- Email -->
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Email Address</label>
                                <Input
                                    v-model="profileForm.email"
                                    type="email"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                                <p v-if="profileForm.errors.email" class="text-xs text-destructive mt-1">{{ profileForm.errors.email }}</p>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <Button 
                                type="submit" 
                                :disabled="profileForm.processing"
                                class="bg-primary hover:opacity-90 text-black font-bold flex items-center gap-1.5 cursor-pointer h-9 px-4 rounded-lg text-xs"
                            >
                                <Loader2 v-if="profileForm.processing" class="h-3.5 w-3.5 animate-spin" />
                                <Save v-else class="h-3.5 w-3.5" />
                                <span>Save Changes</span>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <!-- Change Password Card -->
            <Card class="bg-card border-border shadow-xl">
                <CardHeader class="border-b border-border py-4">
                    <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                        <Key class="h-4 w-4 text-primary" />
                        Update Password
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-6">
                    <form @submit.prevent="submitPassword" class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-3">
                            <!-- Current Password -->
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Current Password</label>
                                <Input
                                    v-model="passwordForm.current_password"
                                    type="password"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                                <p v-if="passwordForm.errors.current_password" class="text-xs text-destructive mt-1">{{ passwordForm.errors.current_password }}</p>
                            </div>

                            <!-- New Password -->
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">New Password</label>
                                <Input
                                    v-model="passwordForm.password"
                                    type="password"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                                <p v-if="passwordForm.errors.password" class="text-xs text-destructive mt-1">{{ passwordForm.errors.password }}</p>
                            </div>

                            <!-- Confirm Password -->
                            <div class="space-y-1.5">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Confirm New Password</label>
                                <Input
                                    v-model="passwordForm.password_confirmation"
                                    type="password"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                                />
                                <p v-if="passwordForm.errors.password_confirmation" class="text-xs text-destructive mt-1">{{ passwordForm.errors.password_confirmation }}</p>
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <Button 
                                type="submit" 
                                :disabled="passwordForm.processing"
                                class="bg-primary hover:opacity-90 text-black font-bold flex items-center gap-1.5 cursor-pointer h-9 px-4 rounded-lg text-xs"
                            >
                                <Loader2 v-if="passwordForm.processing" class="h-3.5 w-3.5 animate-spin" />
                                <Save v-else class="h-3.5 w-3.5" />
                                <span>Update Password</span>
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
