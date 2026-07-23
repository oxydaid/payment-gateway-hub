<script setup lang="ts">
import { ref, watch, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { 
    LayoutDashboard, 
    ArrowLeftRight, 
    CreditCard, 
    Settings, 
    LogOut, 
    Menu, 
    X,
    User,
    CheckCircle,
    AlertTriangle,
    Shield,
    Sun,
    Moon,
    BookOpen
} from '@lucide/vue';
import { 
    DropdownMenu, 
    DropdownMenuContent, 
    DropdownMenuItem, 
    DropdownMenuLabel, 
    DropdownMenuSeparator, 
    DropdownMenuTrigger 
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';

defineProps<{
    title?: string;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const settings = computed(() => page.props.app_settings);



const isMobileMenuOpen = ref(false);
const showNotification = ref(false);
const notificationMessage = ref('');
const notificationType = ref<'success' | 'error'>('success');

const theme = ref(localStorage.getItem('theme') || 'dark');

function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
    localStorage.setItem('theme', theme.value);
    applyTheme();
}

function applyTheme() {
    if (theme.value === 'dark') {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

onMounted(() => {
    applyTheme();
});

// Dynamic App Settings Colors Sync
watch(() => settings.value, (newSettings) => {
    if (newSettings?.primary_color) {
        document.documentElement.style.setProperty('--primary', newSettings.primary_color);
        document.documentElement.style.setProperty('--ring', newSettings.primary_color);
        document.documentElement.style.setProperty('--sidebar-primary', newSettings.primary_color);
    }
    if (newSettings?.secondary_color) {
        document.documentElement.style.setProperty('--secondary', newSettings.secondary_color);
    }
}, { immediate: true, deep: true });

// Use Inertia v3's dedicated 'flash' event - fires whenever Inertia::flash() data arrives.
// This is the correct approach per Inertia v3 docs: https://inertiajs.com/docs/v3/data-props/flash-data
let removeFlashListener: (() => void) | null = null;

onMounted(() => {
    removeFlashListener = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as { success?: string; error?: string } | null;
        if (flash?.success) {
            triggerNotification(flash.success, 'success');
        } else if (flash?.error) {
            triggerNotification(flash.error, 'error');
        }
    });
});

onUnmounted(() => {
    removeFlashListener?.();
});

function triggerNotification(message: string, type: 'success' | 'error') {
    notificationMessage.value = message;
    notificationType.value = type;
    showNotification.value = true;
    setTimeout(() => {
        showNotification.value = false;
    }, 5000);
}

function logout() {
    router.post('/logout');
}

const currentRoute = computed(() => window.location.pathname);

const navItems = [
    { name: 'Dashboard', href: '/', icon: LayoutDashboard },
    { name: 'Transactions', href: '/transactions', icon: ArrowLeftRight },
    { name: 'Merchants', href: '/merchants', icon: User },
    { name: 'API Keys', href: '/api-keys', icon: Shield },
    { name: 'Payment Gateways', href: '/payment-gateways', icon: CreditCard },
    { name: 'App Settings', href: '/app-settings', icon: Settings },
    { name: 'API Docs', href: '/docs/api', icon: BookOpen, external: true },
    { name: 'Webhook Docs', href: '/docs/webhook', icon: BookOpen },
];

function isRouteActive(href: string): boolean {
    if (href === '/') {
        return currentRoute.value === '/';
    }
    return currentRoute.value.startsWith(href);
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground font-sans flex selection:bg-primary selection:text-black">
        <!-- Toast Notification -->
        <Transition
            enter-active-class="transform ease-out duration-300 transition"
            enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
            enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
            leave-active-class="transition ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showNotification" class="fixed top-4 right-4 z-50 flex w-full max-w-sm overflow-hidden rounded-lg bg-card border border-border shadow-2xl">
                <div class="flex items-center p-4 w-full">
                    <div class="flex-shrink-0">
                        <CheckCircle v-if="notificationType === 'success'" class="h-6 w-6 text-primary" />
                        <AlertTriangle v-else class="h-6 w-6 text-destructive" />
                    </div>
                    <div class="ml-3 flex-1">
                        <p class="text-sm font-semibold text-foreground">
                            {{ notificationType === 'success' ? 'Success' : 'Error' }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ notificationMessage }}
                        </p>
                    </div>
                    <div class="ml-4 flex flex-shrink-0">
                        <button @click="showNotification = false" class="inline-flex rounded-md text-muted-foreground hover:text-foreground focus:outline-none cursor-pointer">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Sidebar (Desktop) -->
        <aside class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 bg-card border-r border-border z-20">
            <div class="flex flex-col flex-1 min-h-0">
                <!-- Sidebar Header / Logo -->
                <div class="flex items-center h-16 px-6 bg-card border-b border-border gap-3">
                    <div class="flex items-center justify-center h-8 w-8 rounded-full bg-primary/10 text-primary">
                        <img 
                            v-if="settings?.logo_url" 
                            :src="settings.logo_url" 
                            :alt="settings.app_name" 
                            class="h-6 w-6 object-contain"
                        />
                        <Shield v-else class="h-5 w-5" />
                    </div>
                    <span class="font-extrabold text-lg tracking-tight truncate text-foreground">
                        {{ settings?.app_name || 'Payment Bridge' }}
                    </span>
                </div>

                <!-- Nav Menu -->
                <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
                    <template v-for="item in navItems" :key="item.name">
                        <a
                            v-if="item.external"
                            :href="item.href"
                            target="_blank"
                            class="text-muted-foreground hover:text-foreground hover:bg-muted/50 group flex items-center px-4 py-3 text-sm font-semibold rounded-lg transition-all duration-200"
                        >
                            <component :is="item.icon" class="mr-3 h-5 w-5 flex-shrink-0" />
                            {{ item.name }}
                        </a>
                        <Link
                            v-else
                            :href="item.href"
                            :class="[
                                isRouteActive(item.href)
                                    ? 'bg-muted text-primary font-bold shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground hover:bg-muted/50',
                                'group flex items-center px-4 py-3 text-sm font-semibold rounded-lg transition-all duration-200'
                            ]"
                        >
                            <component :is="item.icon" class="mr-3 h-5 w-5 flex-shrink-0" />
                            {{ item.name }}
                        </Link>
                    </template>
                </nav>

                <!-- Footer / Profile Info -->
                <div class="p-4 border-t border-border bg-card">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="flex items-center justify-center h-9 w-9 rounded-full bg-muted text-foreground">
                                <User class="h-5 w-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold truncate text-foreground">{{ user?.name || 'Super Admin' }}</p>
                                <p class="text-xs text-muted-foreground truncate">{{ user?.email }}</p>
                            </div>
                        </div>
                        <button @click="logout" class="p-2 text-muted-foreground hover:text-destructive rounded-lg transition-colors cursor-pointer" title="Log out">
                            <LogOut class="h-5 w-5" />
                        </button>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Wrapper -->
        <div class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 max-w-full overflow-x-hidden">
            <!-- Header (Mobile + Top Bar) -->
            <header class="sticky top-0 z-10 flex h-16 flex-shrink-0 bg-card/90 backdrop-blur-md border-b border-border">
                <button
                    @click="isMobileMenuOpen = true"
                    class="px-4 text-muted-foreground hover:text-foreground md:hidden focus:outline-none cursor-pointer"
                >
                    <Menu class="h-6 w-6" />
                </button>

                <!-- Top bar contents -->
                <div class="flex flex-1 justify-between px-6 items-center">
                    <div>
                        <!-- Mobile branding logo & name -->
                        <span class="font-extrabold text-sm tracking-tight truncate text-foreground flex md:hidden items-center gap-2">
                            <img 
                                v-if="settings?.logo_url" 
                                :src="settings.logo_url" 
                                :alt="settings.app_name" 
                                class="h-5 w-5 object-contain"
                            />
                            {{ settings?.app_name || 'Payment Bridge' }}
                        </span>
                    </div>

                    <!-- User actions (Desktop) -->
                    <div class="flex items-center gap-4">
                        <Button 
                            variant="ghost" 
                            @click="toggleTheme" 
                            class="h-9 w-9 rounded-full bg-muted border border-border p-0 hover:bg-muted text-foreground cursor-pointer transition-transform hover:scale-105"
                            title="Toggle Dark / Light Mode"
                        >
                            <Sun v-if="theme === 'dark'" class="h-4.5 w-4.5 text-yellow-400" />
                            <Moon v-else class="h-4.5 w-4.5 text-foreground" />
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" class="h-9 w-9 rounded-full bg-muted border border-border p-0 hover:bg-muted focus:ring-0 cursor-pointer">
                                    <User class="h-5 w-5 text-foreground" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56 bg-card border-border text-foreground shadow-2xl">
                                <DropdownMenuLabel class="text-muted-foreground font-normal">My Account</DropdownMenuLabel>
                                <DropdownMenuSeparator class="bg-border" />
                                <DropdownMenuItem class="hover:bg-muted cursor-pointer">
                                    <div class="flex flex-col">
                                        <span class="font-semibold text-foreground">{{ user?.name }}</span>
                                        <span class="text-xs text-muted-foreground">{{ user?.email }}</span>
                                    </div>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator class="bg-border" />
                                <DropdownMenuItem as-child class="hover:bg-muted cursor-pointer text-foreground">
                                    <Link href="/profile" class="flex w-full items-center">
                                        <User class="mr-2 h-4 w-4 text-muted-foreground" />
                                        <span>Edit Profile</span>
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator class="bg-border" />
                                <DropdownMenuItem @click="logout" class="hover:bg-muted text-destructive focus:text-destructive cursor-pointer">
                                    <LogOut class="mr-2 h-4 w-4" />
                                    <span>Log out</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="flex-1 p-4 sm:p-6 md:p-8 min-w-0 max-w-full overflow-x-hidden">
                <!-- Page Heading (Rendered in Content Area to prevent navbar wrapping on mobile) -->
                <div v-if="title" class="mb-6">
                    <h1 class="text-2xl font-extrabold tracking-tight text-foreground">
                        {{ title }}
                    </h1>
                </div>
                <slot />
            </main>
        </div>

        <!-- Sidebar Backdrop (Mobile) -->
        <Transition
            enter-active-class="transition-opacity ease-linear duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity ease-linear duration-300"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isMobileMenuOpen" class="fixed inset-0 bg-black/80 z-30 md:hidden" @click="isMobileMenuOpen = false" />
        </Transition>

        <!-- Sidebar Drawer (Mobile) -->
        <Transition
            enter-active-class="transition ease-in-out duration-300 transform"
            enter-from-class="-translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition ease-in-out duration-300 transform"
            leave-from-class="translate-x-0"
            leave-to-class="-translate-x-full"
        >
            <aside v-if="isMobileMenuOpen" class="fixed inset-y-0 left-0 flex flex-col w-64 bg-card border-r border-border z-40 md:hidden">
                <!-- Close Button -->
                <div class="flex items-center justify-between h-16 px-6 border-b border-border">
                    <div class="flex items-center gap-2">
                        <Shield class="h-5 w-5 text-primary" />
                        <span class="font-bold text-foreground">{{ settings?.app_name || 'Payment Bridge' }}</span>
                    </div>
                    <button @click="isMobileMenuOpen = false" class="text-muted-foreground hover:text-foreground cursor-pointer">
                        <X class="h-6 w-6" />
                    </button>
                </div>

                <!-- Nav Menu -->
                <nav class="flex-1 px-4 py-4 space-y-1 overflow-y-auto" @click="isMobileMenuOpen = false">
                    <template v-for="item in navItems" :key="item.name">
                        <a
                            v-if="item.external"
                            :href="item.href"
                            target="_blank"
                            class="text-muted-foreground hover:text-foreground hover:bg-muted/50 group flex items-center px-4 py-3 text-sm font-semibold rounded-lg"
                        >
                            <component :is="item.icon" class="mr-3 h-5 w-5 flex-shrink-0" />
                            {{ item.name }}
                        </a>
                        <Link
                            v-else
                            :href="item.href"
                            :class="[
                                isRouteActive(item.href)
                                    ? 'bg-muted text-primary font-bold shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground hover:bg-muted/50',
                                'group flex items-center px-4 py-3 text-sm font-semibold rounded-lg'
                            ]"
                        >
                            <component :is="item.icon" class="mr-3 h-5 w-5 flex-shrink-0" />
                            {{ item.name }}
                        </Link>
                    </template>
                </nav>

                <!-- Footer -->
                <div class="p-4 border-t border-border bg-card">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center justify-center h-8 w-8 rounded-full bg-muted">
                                <User class="h-4 w-4 text-foreground" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold truncate text-foreground">{{ user?.name }}</p>
                                <p class="text-[10px] text-muted-foreground truncate">{{ user?.email }}</p>
                            </div>
                        </div>
                        <button @click="logout" class="p-2 text-muted-foreground hover:text-destructive cursor-pointer">
                            <LogOut class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </aside>
        </Transition>
    </div>
</template>
