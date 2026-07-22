<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Save, Image, Palette, LayoutGrid, Loader2 } from '@lucide/vue';

interface AppSettings {
    app_name: string;
    logo_url: string | null;
    favicon_url: string | null;
    primary_color: string;
    secondary_color: string;
}

const props = defineProps<{
    settings: AppSettings;
}>();

const logoPreview = ref<string | null>(props.settings.logo_url);
const faviconPreview = ref<string | null>(props.settings.favicon_url);

const form = useForm({
    app_name: props.settings.app_name,
    primary_color: props.settings.primary_color,
    secondary_color: props.settings.secondary_color,
    logo: null as File | null,
    favicon: null as File | null,
});

function handleLogoChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        logoPreview.value = URL.createObjectURL(file);
    }
}

function handleFaviconChange(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        form.favicon = file;
        faviconPreview.value = URL.createObjectURL(file);
    }
}

function submit() {
    form.post('/app-settings', {
        onSuccess: () => {
            // Flash messages are automatically handled by AdminLayout
        },
        preserveScroll: true
    });
}
</script>

<template>
    <Head title="App Settings & Branding" />

    <AdminLayout title="App Settings">
        <div>
            <form @submit.prevent="submit" class="space-y-6">
                <!-- Branding Card -->
                <Card class="bg-card border-border shadow-xl">
                    <CardHeader class="border-b border-border py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                            <LayoutGrid class="h-4 w-4 text-primary" />
                            Branding Identity
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="p-6 space-y-4">
                        <!-- App Name -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Application Name</label>
                            <Input
                                v-model="form.app_name"
                                type="text"
                                required
                                placeholder="Enter application name..."
                                class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                            />
                            <p v-if="form.errors.app_name" class="text-xs text-destructive">{{ form.errors.app_name }}</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Colors Card -->
                <Card class="bg-card border-border shadow-xl">
                    <CardHeader class="border-b border-border py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                            <Palette class="h-4 w-4 text-primary" />
                            Theme Colors
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="p-6 grid gap-6 md:grid-cols-2">
                        <!-- Primary Color -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Primary Accent Color</label>
                            <div class="flex gap-3 items-center">
                                <input
                                    v-model="form.primary_color"
                                    type="color"
                                    class="h-10 w-12 bg-transparent border border-border rounded cursor-pointer"
                                />
                                <Input
                                    v-model="form.primary_color"
                                    type="text"
                                    pattern="^#[0-9a-fA-F]{6}$"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1 font-mono uppercase"
                                />
                            </div>
                            <p v-if="form.errors.primary_color" class="text-xs text-destructive">{{ form.errors.primary_color }}</p>
                        </div>

                        <!-- Secondary Color -->
                        <div class="space-y-2">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Secondary Accent Color</label>
                            <div class="flex gap-3 items-center">
                                <input
                                    v-model="form.secondary_color"
                                    type="color"
                                    class="h-10 w-12 bg-transparent border border-border rounded cursor-pointer"
                                />
                                <Input
                                    v-model="form.secondary_color"
                                    type="text"
                                    pattern="^#[0-9a-fA-F]{6}$"
                                    required
                                    class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1 font-mono uppercase"
                                />
                            </div>
                            <p v-if="form.errors.secondary_color" class="text-xs text-destructive">{{ form.errors.secondary_color }}</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Assets (Logo / Favicon) Card -->
                <Card class="bg-card border-border shadow-xl">
                    <CardHeader class="border-b border-border py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                            <Image class="h-4 w-4 text-primary" />
                            Design Assets
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="p-6 grid gap-6 md:grid-cols-2">
                        <!-- Logo Upload -->
                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Application Logo</label>
                            
                            <div class="flex items-center gap-4 p-4 rounded-xl bg-muted/40 border border-border">
                                <div class="h-16 w-16 bg-muted rounded-lg overflow-hidden flex items-center justify-center border border-border">
                                    <img v-if="logoPreview" :src="logoPreview" alt="Logo preview" class="h-full w-full object-contain p-2" />
                                    <Image v-else class="h-6 w-6 text-muted-foreground" />
                                </div>
                                <div class="space-y-1">
                                    <input
                                        type="file"
                                        accept="image/*"
                                        id="logo-upload"
                                        class="hidden"
                                        @change="handleLogoChange"
                                    />
                                    <label
                                        for="logo-upload"
                                        class="inline-block px-3 py-1.5 bg-muted hover:bg-muted/80 text-foreground font-bold text-xs uppercase tracking-wider rounded-lg cursor-pointer border border-border"
                                    >
                                        Choose File
                                    </label>
                                    <p class="text-[10px] text-muted-foreground">Max file size: 2MB (PNG, JPG, SVG)</p>
                                </div>
                            </div>
                            <p v-if="form.errors.logo" class="text-xs text-destructive">{{ form.errors.logo }}</p>
                        </div>

                        <!-- Favicon Upload -->
                        <div class="space-y-3">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Favicon Icon</label>
                            
                            <div class="flex items-center gap-4 p-4 rounded-xl bg-muted/40 border border-border">
                                <div class="h-16 w-16 bg-muted rounded-lg overflow-hidden flex items-center justify-center border border-border">
                                    <img v-if="faviconPreview" :src="faviconPreview" alt="Favicon preview" class="h-8 w-8 object-contain" />
                                    <Image v-else class="h-6 w-6 text-muted-foreground" />
                                </div>
                                <div class="space-y-1">
                                    <input
                                        type="file"
                                        accept="image/*"
                                        id="favicon-upload"
                                        class="hidden"
                                        @change="handleFaviconChange"
                                    />
                                    <label
                                        for="favicon-upload"
                                        class="inline-block px-3 py-1.5 bg-muted hover:bg-muted/80 text-foreground font-bold text-xs uppercase tracking-wider rounded-lg cursor-pointer border border-border"
                                    >
                                        Choose File
                                    </label>
                                    <p class="text-[10px] text-muted-foreground">Max file size: 1MB (PNG, ICO, SVG)</p>
                                </div>
                            </div>
                            <p v-if="form.errors.favicon" class="text-xs text-destructive">{{ form.errors.favicon }}</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Submit Section -->
                <div class="flex justify-end pt-2">
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="bg-primary hover:opacity-90 text-black font-bold uppercase tracking-wider h-11 px-8 rounded-full transition-all hover:scale-[1.03] active:scale-[0.97] cursor-pointer"
                    >
                        <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                        <Save v-else class="mr-2 h-4 w-4" />
                        Save Settings
                    </Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
