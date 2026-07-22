<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Switch } from '@/components/ui/switch';
import { 
    Dialog, 
    DialogContent, 
    DialogHeader, 
    DialogTitle, 
    DialogDescription,
    DialogFooter 
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Settings, ShieldCheck, Plus, Trash2, Edit2, Loader2, DollarSign, Cpu, Image } from '@lucide/vue';

interface MethodItem {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    fee_type: 'fix' | 'percent' | 'mix';
    fee_percent: number;
    fee_fix: number;
    icon_url: string | null;
}

interface GatewayItem {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    credentials: Record<string, any>;
    payment_methods: MethodItem[];
    icon_url: string | null;
}

interface DriverField {
    key: string;
    label: string;
    type: string;
    placeholder?: string;
}

interface AvailableDriver {
    name: string;
    code: string;
    fields: DriverField[];
}

const props = defineProps<{
    gateways: GatewayItem[];
    available_drivers: AvailableDriver[];
}>();

const gatewaysList = ref(props.gateways.map(g => ({
    ...g,
    payment_methods: g.payment_methods.map(m => ({ ...m }))
})));

watch(() => props.gateways, (newGateways) => {
    gatewaysList.value = newGateways.map(g => ({
        ...g,
        payment_methods: g.payment_methods.map(m => ({ ...m }))
    }));
}, { deep: true });

// Reset fields when fee_type changes
watch(() => methodForm.fee_type, (newType) => {
    if (newType === 'fix') {
        methodForm.fee_percent = 0;
    } else if (newType === 'percent') {
        methodForm.fee_fix = 0;
    }
});

const isCredentialsOpen = ref(false);
const isGatewayCreateOpen = ref(false);
const isMethodCreateOpen = ref(false);
const isMethodEditOpen = ref(false);

const currentGateway = ref<GatewayItem | null>(null);
const currentMethod = ref<MethodItem | null>(null);
const selectedDriverCode = ref('');

// Previews
const createMethodIconPreview = ref<string | null>(null);
const editMethodIconPreview = ref<string | null>(null);

// Forms
const credentialsForm = useForm({
    name: '',
    credentials: {} as Record<string, any>,
    is_active: false,
});

const gatewayForm = useForm({
    name: '',
    code: '',
    is_active: true,
    credentials: {} as Record<string, any>,
});

const methodForm = useForm({
    name: '',
    code: '',
    type: 'qris' as 'va' | 'qris' | 'ewallet' | 'retail' | 'credit_card',
    fee_type: 'percent' as 'fix' | 'percent' | 'mix',
    fee_percent: 0,
    fee_fix: 0,
    is_active: true,
    icon: null as File | null,
});

function handleMethodCreateIcon(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        methodForm.icon = file;
        createMethodIconPreview.value = URL.createObjectURL(file);
    }
}

function handleMethodEditIcon(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
        methodForm.icon = file;
        editMethodIconPreview.value = URL.createObjectURL(file);
    }
}

const activeCreateDriver = computed(() => {
    return props.available_drivers.find(d => d.code === selectedDriverCode.value);
});

const activeEditDriver = computed(() => {
    if (!currentGateway.value) return null;
    return props.available_drivers.find(d => d.code === currentGateway.value?.code);
});

function onDriverSelected() {
    const driver = activeCreateDriver.value;
    if (!driver) return;

    gatewayForm.name = driver.name;
    gatewayForm.code = driver.code;
    
    const creds: Record<string, any> = { is_production: false };
    driver.fields.forEach(f => {
        creds[f.key] = '';
    });
    gatewayForm.credentials = creds;
}

function openCreateGateway() {
    gatewayForm.reset();
    gatewayForm.clearErrors();
    selectedDriverCode.value = props.available_drivers[0]?.code || '';
    onDriverSelected();
    isGatewayCreateOpen.value = true;
}

function submitCreateGateway() {
    gatewayForm.post('/payment-gateways', {
        onSuccess: () => {
            isGatewayCreateOpen.value = false;
        }
    });
}

function openCredentials(gateway: GatewayItem) {
    currentGateway.value = gateway;
    credentialsForm.name = gateway.name;
    credentialsForm.is_active = gateway.is_active;
    credentialsForm.clearErrors();
    
    const driver = props.available_drivers.find(d => d.code === gateway.code);
    const creds: Record<string, any> = {
        is_production: gateway.credentials?.is_production ?? false
    };

    if (driver) {
        driver.fields.forEach(f => {
            creds[f.key] = gateway.credentials?.[f.key] || '';
        });
    } else {
        Object.assign(creds, gateway.credentials || {});
    }

    credentialsForm.credentials = creds;
    isCredentialsOpen.value = true;
}

function saveCredentials() {
    if (!currentGateway.value) return;
    credentialsForm.post(`/payment-gateways/${currentGateway.value.id}/update`, {
        onSuccess: () => {
            isCredentialsOpen.value = false;
        }
    });
}

function deleteGateway(gateway: GatewayItem) {
    if (confirm(`Delete payment gateway "${gateway.name}" and all of its channels?`)) {
        router.delete(`/payment-gateways/${gateway.id}`, { preserveScroll: true });
    }
}

// Method operations
function openCreateMethod(gateway: GatewayItem) {
    currentGateway.value = gateway;
    methodForm.reset();
    methodForm.clearErrors();
    createMethodIconPreview.value = null;
    isMethodCreateOpen.value = true;
}

function submitCreateMethod() {
    if (!currentGateway.value) return;
    methodForm.post(`/payment-gateways/${currentGateway.value.id}/methods`, {
        onSuccess: () => {
            isMethodCreateOpen.value = false;
        }
    });
}

function openEditMethod(method: MethodItem) {
    currentMethod.value = method;
    methodForm.name = method.name;
    methodForm.code = method.code;
    methodForm.fee_type = method.fee_type;
    methodForm.fee_percent = method.fee_percent;
    methodForm.fee_fix = method.fee_fix;
    methodForm.is_active = method.is_active;
    methodForm.icon = null;
    editMethodIconPreview.value = method.icon_url;
    methodForm.clearErrors();
    isMethodEditOpen.value = true;
}

function submitEditMethod() {
    if (!currentMethod.value) return;
    methodForm.post(`/payment-gateways/methods/${currentMethod.value.id}/update`, {
        onSuccess: () => {
            isMethodEditOpen.value = false;
        }
    });
}

function deleteMethod(method: MethodItem) {
    if (confirm(`Remove payment channel "${method.name}"?`)) {
        router.delete(`/payment-gateways/methods/${method.id}`, { preserveScroll: true });
    }
}


function formatIdr(value: number): string {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(value);
}
</script>

<template>
    <Head title="Payment Gateways Integration" />

    <AdminLayout title="Payment Gateways">
        <div class="space-y-8">
            <!-- Header Intro & Add Gateway -->
            <div class="bg-card border border-border p-6 rounded-xl shadow-lg flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-lg font-bold text-foreground">Gateway Integrations</h2>
                    <p class="text-sm text-muted-foreground">Activate channels, edit fees, and configure secure client/server API credentials.</p>
                </div>
                <div class="flex items-center gap-3">
                    <Button 
                        @click="openCreateGateway"
                        class="bg-muted text-foreground hover:bg-muted/80 border-none font-bold text-xs uppercase tracking-wider h-10 px-4 rounded-lg flex items-center gap-1.5 cursor-pointer"
                    >
                        <Plus class="h-4 w-4" />
                        Add Gateway
                    </Button>
                    <div class="flex items-center gap-2 text-xs font-bold text-primary bg-primary/10 border border-primary/25 px-3 py-1.5 rounded-full uppercase tracking-wider">
                        <ShieldCheck class="h-4 w-4" />
                        Secure Key Storage
                    </div>
                </div>
            </div>

            <!-- Gateways List -->
            <div v-for="gateway in gatewaysList" :key="gateway.id" class="bg-card border border-border rounded-xl shadow-xl overflow-hidden">
                <!-- Gateway Header -->
                <div class="px-6 py-5 border-b border-border flex items-center justify-between flex-wrap gap-4 bg-muted/30">
                    <div class="flex items-center gap-3">
                        <div class="h-10 w-10 rounded-lg bg-muted flex items-center justify-center font-bold text-foreground border border-border overflow-hidden">
                            <img v-if="gateway.icon_url" :src="gateway.icon_url" class="h-full w-full object-contain p-1" />
                            <span v-else class="uppercase tracking-wide">{{ gateway.code.substring(0, 2) }}</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-foreground flex items-center gap-2">
                                {{ gateway.name }}
                                <span :class="['px-2 py-0.5 rounded text-[10px] font-bold border', gateway.is_active ? 'bg-primary/10 text-primary border-primary/20' : 'bg-destructive/10 text-destructive border-destructive/20']">
                                    {{ gateway.is_active ? 'Active' : 'Disabled' }}
                                </span>
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">Code identifier: <code class="font-mono">{{ gateway.code }}</code></p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button 
                            variant="secondary"
                            @click="openCredentials(gateway)"
                            class="bg-muted text-foreground border-none hover:bg-muted/80 h-9 text-xs font-bold rounded-lg uppercase tracking-wider px-4 flex items-center gap-1.5 cursor-pointer"
                        >
                            <Settings class="h-4 w-4" />
                            Credentials
                        </Button>

                        <button 
                            @click="deleteGateway(gateway)"
                            class="p-2 bg-destructive/10 text-destructive border border-destructive/20 rounded-lg hover:bg-destructive hover:text-white transition-colors cursor-pointer"
                            title="Delete Gateway"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>


                    </div>
                </div>

                <!-- Gateway Payment Channels -->
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Payment Methods / Channels</h4>
                        <Button
                            size="sm"
                            @click="openCreateMethod(gateway)"
                            :disabled="!gateway.is_active"
                            class="bg-muted text-muted-foreground hover:text-foreground hover:bg-muted/80 h-8 px-3 rounded-lg flex items-center gap-1.5 text-xs font-bold cursor-pointer"
                        >
                            <Plus class="h-3.5 w-3.5" />
                            Add Channel
                        </Button>
                    </div>
                    
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div 
                            v-for="method in gateway.payment_methods" 
                            :key="method.id"
                            class="bg-muted/40 border border-border p-4 rounded-xl flex flex-col justify-between hover:bg-muted/70 transition-colors"
                        >
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="h-8 w-8 rounded bg-muted flex items-center justify-center border border-border overflow-hidden">
                                        <img v-if="method.icon_url" :src="method.icon_url" class="h-full w-full object-contain p-0.5" />
                                        <DollarSign v-else class="h-4 w-4 text-muted-foreground" />
                                    </div>
                                    <div class="space-y-0.5">
                                        <span class="text-sm font-semibold text-foreground">{{ method.name }}</span>
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="bg-muted text-muted-foreground px-1.5 py-0.5 rounded text-[10px] font-mono uppercase font-bold border border-border">
                                                {{ method.code }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <span 
                                    :class="[
                                        'px-2 py-0.5 rounded text-[10px] font-bold border', 
                                        method.is_active 
                                            ? 'bg-primary/10 text-primary border-primary/20' 
                                            : 'bg-destructive/10 text-destructive border-destructive/20'
                                    ]"
                                >
                                    {{ method.is_active ? 'Active' : 'Disabled' }}
                                </span>
                            </div>

                            <div class="border-t border-border mt-3 pt-3 flex items-center justify-between text-xs text-muted-foreground">
                                <div class="space-y-0.5">
                                    <p v-if="method.fee_type === 'mix'">
                                        Fee: <span class="font-bold text-foreground">{{ formatIdr(method.fee_fix) }} + {{ method.fee_percent }}%</span>
                                    </p>
                                    <p v-else-if="method.fee_type === 'percent'">
                                        Fee: <span class="font-bold text-foreground">{{ method.fee_percent }}%</span>
                                    </p>
                                    <p v-else>
                                        Fee: <span class="font-bold text-foreground">{{ formatIdr(method.fee_fix) }}</span>
                                    </p>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button 
                                        @click="openEditMethod(method)"
                                        class="p-1.5 rounded bg-muted hover:bg-primary text-muted-foreground hover:text-black transition-colors cursor-pointer border border-border"
                                    >
                                        <Edit2 class="h-3 w-3" />
                                    </button>
                                    <button 
                                        @click="deleteMethod(method)"
                                        class="p-1.5 rounded bg-muted hover:bg-destructive text-muted-foreground hover:text-white transition-colors cursor-pointer border border-border"
                                    >
                                        <Trash2 class="h-3 w-3" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Gateway Dialog (With Dynamic Driver Selector & Error Alerts) -->
        <Dialog :open="isGatewayCreateOpen" @update:open="isGatewayCreateOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-lg shadow-2xl">
                <DialogHeader>
                    <DialogTitle class="text-foreground flex items-center gap-2">
                        <Cpu class="h-5 w-5 text-primary" />
                        Add Registered Payment Gateway
                    </DialogTitle>
                    <DialogDescription class="text-muted-foreground text-xs">
                        Select an installed payment driver from the backend service layer to auto-configure required fields.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitCreateGateway" class="space-y-4">
                    <!-- Driver Selection Dropdown -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Select Registered Driver</label>
                        <select 
                            v-model="selectedDriverCode" 
                            @change="onDriverSelected"
                            class="w-full h-10 px-3 rounded-md bg-muted text-foreground border border-border text-sm focus:ring-primary focus:ring-1 focus:outline-none"
                        >
                            <option v-for="d in available_drivers" :key="d.code" :value="d.code">
                                {{ d.name }} ({{ d.code }})
                            </option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Gateway Name</label>
                            <Input v-model="gatewayForm.name" required class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" />
                            <p v-if="gatewayForm.errors.name" class="text-xs text-destructive mt-1">{{ gatewayForm.errors.name }}</p>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Gateway Code</label>
                            <Input v-model="gatewayForm.code" required readonly class="bg-muted/60 border-none text-muted-foreground font-mono" />
                            <p v-if="gatewayForm.errors.code" class="text-xs text-destructive mt-1">{{ gatewayForm.errors.code }}</p>
                        </div>
                    </div>

                    <!-- Dynamic Credential Fields rendered according to driver schema -->
                    <div v-if="activeCreateDriver" class="space-y-3 border-t border-border pt-3">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-primary">Required Driver Credentials</h4>
                        <div v-for="field in activeCreateDriver.fields" :key="field.key" class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ field.label }}</label>
                            <Input 
                                v-model="gatewayForm.credentials[field.key]" 
                                :type="field.type" 
                                :placeholder="field.placeholder" 
                                required 
                                class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" 
                            />
                        </div>

                        <!-- Production Mode Switch -->
                        <div class="flex items-center space-x-2 pt-2">
                            <input
                                v-model="gatewayForm.credentials.is_production"
                                type="checkbox"
                                id="create_is_production"
                                class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary"
                            />
                            <label for="create_is_production" class="text-xs font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                                Production Mode
                            </label>
                        </div>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isGatewayCreateOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="gatewayForm.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            <Loader2 v-if="gatewayForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span>Save Gateway</span>
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Create Method Dialog -->
        <Dialog :open="isMethodCreateOpen" @update:open="isMethodCreateOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-foreground">Add Payment Channel ({{ currentGateway?.name }})</DialogTitle>
                    <DialogDescription class="text-muted-foreground text-xs">
                        Register a new payment channel under this gateway.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitCreateMethod" class="space-y-4">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Method Name</label>
                        <Input v-model="methodForm.name" required placeholder="e.g. QRIS, Mandiri Virtual Account" class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" />
                        <p v-if="methodForm.errors.name" class="text-xs text-destructive mt-1">{{ methodForm.errors.name }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Method Code</label>
                            <Input v-model="methodForm.code" required placeholder="e.g. qris, mandiriva" class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" />
                            <p v-if="methodForm.errors.code" class="text-xs text-destructive mt-1">{{ methodForm.errors.code }}</p>
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Category Type</label>
                            <select v-model="methodForm.type" class="w-full h-10 px-3 rounded-md bg-muted text-foreground border-none text-sm focus:ring-primary">
                                <option value="qris">QRIS</option>
                                <option value="va">Virtual Account</option>
                                <option value="ewallet">E-Wallet</option>
                                <option value="retail">Retail Outlet</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </div>
                    </div>

                    <!-- Method Icon Upload -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Channel Icon</label>
                        <div class="flex items-center gap-4 p-3 rounded-xl bg-muted/40 border border-border">
                            <div class="h-10 w-10 bg-muted rounded overflow-hidden flex items-center justify-center border border-border">
                                <img v-if="createMethodIconPreview" :src="createMethodIconPreview" class="h-full w-full object-contain p-1" />
                                <Image v-else class="h-4 w-4 text-muted-foreground" />
                            </div>
                            <div class="space-y-0.5">
                                <input type="file" accept="image/*" id="method-create-icon" class="hidden" @change="handleMethodCreateIcon" />
                                <label for="method-create-icon" class="inline-block px-2.5 py-1.5 bg-muted hover:bg-muted/80 text-foreground font-bold text-[10px] uppercase tracking-wider rounded border border-border cursor-pointer">
                                    Choose Icon
                                </label>
                            </div>
                        </div>
                        <p v-if="methodForm.errors.icon" class="text-xs text-destructive mt-1">{{ methodForm.errors.icon }}</p>
                    </div>

                    <div class="space-y-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fee Type</label>
                            <select v-model="methodForm.fee_type" class="w-full h-10 px-3 rounded-md bg-muted text-foreground border-none text-sm focus:ring-primary">
                                <option value="percent">Percentage (%)</option>
                                <option value="fix">Fixed Amount (IDR)</option>
                                <option value="mix">Mix (Fixed + %)</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div v-if="methodForm.fee_type === 'fix' || methodForm.fee_type === 'mix'" class="space-y-1">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fee (IDR)</label>
                                <Input v-model.number="methodForm.fee_fix" type="number" step="any" required class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" />
                            </div>
                            <div v-if="methodForm.fee_type === 'percent' || methodForm.fee_type === 'mix'" class="space-y-1">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fee (%)</label>
                                <Input v-model.number="methodForm.fee_percent" type="number" step="any" required class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" />
                            </div>
                        </div>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isMethodCreateOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="methodForm.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            Add Channel
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Edit Method Dialog -->
        <Dialog :open="isMethodEditOpen" @update:open="isMethodEditOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-foreground">Edit Channel Fee Configuration</DialogTitle>
                    <DialogDescription class="text-muted-foreground text-xs">
                        Adjust commission fees for payment method "{{ currentMethod?.name }}".
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitEditMethod" class="space-y-4">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Method Name</label>
                        <Input v-model="methodForm.name" required class="bg-muted border-none text-foreground" />
                        <p v-if="methodForm.errors.name" class="text-xs text-destructive mt-1">{{ methodForm.errors.name }}</p>
                    </div>

                    <!-- Method Icon Upload -->
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Channel Icon</label>
                        <div class="flex items-center gap-4 p-3 rounded-xl bg-muted/40 border border-border">
                            <div class="h-10 w-10 bg-muted rounded overflow-hidden flex items-center justify-center border border-border">
                                <img v-if="editMethodIconPreview" :src="editMethodIconPreview" class="h-full w-full object-contain p-1" />
                                <Image v-else class="h-4 w-4 text-muted-foreground" />
                            </div>
                            <div class="space-y-0.5">
                                <input type="file" accept="image/*" id="method-edit-icon" class="hidden" @change="handleMethodEditIcon" />
                                <label for="method-edit-icon" class="inline-block px-2.5 py-1.5 bg-muted hover:bg-muted/80 text-foreground font-bold text-[10px] uppercase tracking-wider rounded border border-border cursor-pointer">
                                    Change Icon
                                </label>
                            </div>
                        </div>
                        <p v-if="methodForm.errors.icon" class="text-xs text-destructive mt-1">{{ methodForm.errors.icon }}</p>
                    </div>

                    <div class="space-y-4">
                        <div class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fee Type</label>
                            <select v-model="methodForm.fee_type" class="w-full h-10 px-3 rounded-md bg-muted text-foreground border-none text-sm focus:ring-primary">
                                <option value="percent">Percentage (%)</option>
                                <option value="fix">Fixed Amount (IDR)</option>
                                <option value="mix">Mix (Fixed + %)</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div v-if="methodForm.fee_type === 'fix' || methodForm.fee_type === 'mix'" class="space-y-1">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fee (IDR)</label>
                                <Input v-model.number="methodForm.fee_fix" type="number" step="any" required class="bg-muted border-none text-foreground" />
                            </div>
                            <div v-if="methodForm.fee_type === 'percent' || methodForm.fee_type === 'mix'" class="space-y-1">
                                <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Fee (%)</label>
                                <Input v-model.number="methodForm.fee_percent" type="number" step="any" required class="bg-muted border-none text-foreground" />
                            </div>
                        </div>
                    </div>

                    <!-- Active State Checkbox -->
                    <div class="flex items-center space-x-2 pt-2 border-t border-border mt-4">
                        <input
                            v-model="methodForm.is_active"
                            type="checkbox"
                            id="edit_method_is_active"
                            class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary cursor-pointer"
                        />
                        <label for="edit_method_is_active" class="text-xs font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                            Active
                        </label>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isMethodEditOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="methodForm.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            Update Channel
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Credentials Dialog Modal (Dynamic for Existing Gateway & Error Alerts) -->
        <Dialog :open="isCredentialsOpen" @update:open="isCredentialsOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg text-foreground">Configure {{ currentGateway?.name }}</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Edit system connection credentials. Values are stored securely with database encryption.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="saveCredentials" class="space-y-4 py-2">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Gateway Display Name</label>
                        <Input
                            v-model="credentialsForm.name"
                            type="text"
                            required
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                        <p v-if="credentialsForm.errors.name" class="text-xs text-destructive mt-1">{{ credentialsForm.errors.name }}</p>
                    </div>

                    <!-- Dynamic schema rendered according to driver -->
                    <template v-if="activeEditDriver">
                        <div v-for="field in activeEditDriver.fields" :key="field.key" class="space-y-1">
                            <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">{{ field.label }}</label>
                            <Input 
                                v-model="credentialsForm.credentials[field.key]" 
                                :type="field.type" 
                                required 
                                class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1" 
                            />
                        </div>
                    </template>

                    <!-- Sandbox/Production Toggle -->
                    <div class="flex items-center space-x-2 pt-2 border-t border-border mt-4">
                        <input
                            v-model="credentialsForm.credentials.is_production"
                            type="checkbox"
                            id="is_production"
                            class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary cursor-pointer"
                        />
                        <label for="is_production" class="text-sm font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                            Production Mode
                        </label>
                    </div>

                    <!-- Active State Checkbox -->
                    <div class="flex items-center space-x-2 pt-2">
                        <input
                            v-model="credentialsForm.is_active"
                            type="checkbox"
                            id="edit_gateway_is_active"
                            class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary cursor-pointer"
                        />
                        <label for="edit_gateway_is_active" class="text-sm font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                            Active
                        </label>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isCredentialsOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="credentialsForm.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            <Loader2 v-if="credentialsForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span>Save credentials</span>
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
