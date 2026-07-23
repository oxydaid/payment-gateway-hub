<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router, Link, useHttp } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { 
    Plus, 
    Search, 
    Copy, 
    Check, 
    Key, 
    Trash2, 
    Edit, 
    RefreshCw, 
    SlidersHorizontal,
    ExternalLink,
    Loader2,
    ShieldAlert,
    Activity
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { 
    Dialog, 
    DialogContent, 
    DialogHeader, 
    DialogTitle, 
    DialogDescription, 
    DialogFooter 
} from '@/components/ui/dialog';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

interface MerchantItem {
    id: number;
    name: string;
    webhook_url: string | null;
    api_key: string | null;
    is_active: boolean;
    transactions_count: number;
    created_at: string;
}

interface PaginatedMerchants {
    data: MerchantItem[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number;
    to: number;
    last_page: number;
}

const props = defineProps<{
    merchants: PaginatedMerchants;
    filters: { search?: string };
}>();

const searchTerm = ref(props.filters.search || '');
const isCreateOpen = ref(false);
const isEditOpen = ref(false);
const editingMerchant = ref<MerchantItem | null>(null);
const copiedKeyId = ref<number | null>(null);

// Webhook testing states
const isTestOpen = ref(false);
const testingMerchant = ref<MerchantItem | null>(null);
const testResult = ref<{
    success: boolean;
    status_code?: number;
    response_body?: string | null;
    duration_ms?: number;
    notes?: string;
    message?: string;
} | null>(null);
const isTestingInProgress = ref(false);

const http = useHttp();

const form = useForm({
    name: '',
    webhook_url: '',
    is_active: true,
});

function applySearch() {
    router.get('/merchants', { search: searchTerm.value }, { preserveState: true, replace: true });
}

function openCreate() {
    form.reset();
    form.clearErrors();
    form.is_active = true;
    isCreateOpen.value = true;
}

function createMerchant() {
    form.post('/merchants', {
        onSuccess: () => {
            isCreateOpen.value = false;
        }
    });
}

function openEdit(merchant: MerchantItem) {
    editingMerchant.value = merchant;
    form.name = merchant.name;
    form.webhook_url = merchant.webhook_url || '';
    form.is_active = Boolean(merchant.is_active);
    form.clearErrors();
    isEditOpen.value = true;
}

function updateMerchant() {
    if (!editingMerchant.value) return;
    form.put(`/merchants/${editingMerchant.value.id}`, {
        onSuccess: () => {
            isEditOpen.value = false;
        }
    });
}

function deleteMerchant(merchant: MerchantItem) {
    if (confirm(`Are you sure you want to delete merchant "${merchant.name}"? This action cannot be undone.`)) {
        router.delete(`/merchants/${merchant.id}`, { preserveScroll: true });
    }
}

function generateKey(merchant: MerchantItem) {
    if (confirm(`Generate a new API key for "${merchant.name}"? The previous key will be updated.`)) {
        router.post(`/merchants/${merchant.id}/generate-key`, {}, { preserveScroll: true });
    }
}

function copyToClipboard(text: string, id: number) {
    navigator.clipboard.writeText(text);
    copiedKeyId.value = id;
    setTimeout(() => {
        copiedKeyId.value = null;
    }, 2000);
}

function openTest(merchant: MerchantItem) {
    testingMerchant.value = merchant;
    testResult.value = null;
    isTestOpen.value = true;
}

function runWebhookTest() {
    if (!testingMerchant.value) return;
    isTestingInProgress.value = true;
    testResult.value = null;

    http.post(`/merchants/${testingMerchant.value.id}/test-webhook`, {
        onSuccess: (response: any) => {
            isTestingInProgress.value = false;
            testResult.value = response;
        },
        onError: () => {
            isTestingInProgress.value = false;
            testResult.value = {
                success: false,
                notes: 'An unexpected error occurred while making the request.',
            };
        }
    });
}
</script>

<template>
    <Head title="Merchants Management" />

    <AdminLayout title="Merchants">
        <div class="space-y-6 max-w-full">
            <!-- Top Controls -->
            <div class="bg-card border border-border p-5 rounded-xl shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <form @submit.prevent="applySearch" class="flex-1 w-full max-w-md relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                        <Search class="h-4 w-4" />
                    </span>
                    <Input
                        v-model="searchTerm"
                        type="text"
                        placeholder="Search merchant name or webhook..."
                        class="pl-10 bg-muted border-none text-foreground placeholder:text-muted-foreground focus-visible:ring-primary focus-visible:ring-1"
                    />
                </form>

                <Button
                    @click="openCreate"
                    class="w-full sm:w-auto bg-primary hover:opacity-90 text-black font-bold uppercase tracking-wider h-10 px-5 rounded-lg flex items-center gap-2"
                >
                    <Plus class="h-4 w-4" />
                    Add Merchant
                </Button>
            </div>

            <!-- Table Container (Responsive overflow wrapper) -->
            <div class="bg-card border border-border rounded-xl shadow-xl overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="w-full text-left border-collapse min-w-[700px] whitespace-nowrap">
                        <thead>
                            <tr class="text-xs font-bold uppercase tracking-wider text-muted-foreground border-b border-border whitespace-nowrap">
                                <th class="px-6 py-4">Merchant Name</th>
                                <th class="px-6 py-4">Webhook URL</th>
                                <th class="px-6 py-4">API Key</th>
                                <th class="px-6 py-4">Transactions</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="m in merchants.data"
                                :key="m.id"
                                class="hover:bg-muted/50 transition-colors text-sm text-muted-foreground whitespace-nowrap"
                            >
                                <td class="px-6 py-4 font-bold text-foreground">{{ m.name }}</td>
                                <td class="px-6 py-4 font-mono text-xs truncate max-w-[200px]">
                                    <span v-if="m.webhook_url" class="text-blue-500 font-medium">{{ m.webhook_url }}</span>
                                    <span v-else class="text-muted-foreground/60 italic">Not configured</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div v-if="m.api_key" class="flex items-center gap-2">
                                        <code class="bg-muted text-foreground px-2 py-0.5 rounded text-xs font-mono border border-border">
                                            {{ m.api_key.substring(0, 14) }}...
                                        </code>
                                        <button
                                            @click="copyToClipboard(m.api_key, m.id)"
                                            class="p-1 text-muted-foreground hover:text-primary transition-colors cursor-pointer"
                                            title="Copy API Key"
                                        >
                                            <Check v-if="copiedKeyId === m.id" class="h-4 w-4 text-primary" />
                                            <Copy v-else class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <span v-else class="text-xs text-muted-foreground/60 italic">No API key</span>
                                </td>
                                <td class="px-6 py-4 font-bold text-foreground">{{ m.transactions_count }}</td>
                                <td class="px-6 py-4">
                                    <span :class="['px-2.5 py-0.5 rounded-full text-xs font-bold border', m.is_active ? 'bg-primary/10 text-primary border-primary/20' : 'bg-destructive/10 text-destructive border-destructive/20']">
                                        {{ m.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            @click="openTest(m)"
                                            class="p-1.5 rounded-md bg-muted text-blue-500 hover:bg-blue-500 hover:text-black transition-colors cursor-pointer border border-border"
                                            title="Test Webhook Connection"
                                        >
                                            <Activity class="h-4 w-4" />
                                        </button>
                                        <button
                                            @click="generateKey(m)"
                                            class="p-1.5 rounded-md bg-muted text-yellow-500 hover:bg-yellow-500 hover:text-black transition-colors cursor-pointer border border-border"
                                            title="Generate New API Key"
                                        >
                                            <Key class="h-4 w-4" />
                                        </button>
                                        <button
                                            @click="openEdit(m)"
                                            class="p-1.5 rounded-md bg-muted text-foreground hover:bg-primary hover:text-black transition-colors cursor-pointer border border-border"
                                            title="Edit Merchant"
                                        >
                                            <Edit class="h-4 w-4" />
                                        </button>
                                        <button
                                            @click="deleteMerchant(m)"
                                            class="p-1.5 rounded-md bg-muted text-destructive hover:bg-destructive hover:text-white transition-colors cursor-pointer border border-border"
                                            title="Delete Merchant"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="merchants.data.length === 0">
                                <td colspan="6" class="text-center py-12 text-muted-foreground italic text-sm">
                                    No merchants found. Create a merchant to issue API keys.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="merchants.last_page > 1" class="flex flex-col sm:flex-row items-center justify-between gap-4 py-4 px-1">
                <span class="text-xs text-muted-foreground">
                    Showing {{ merchants.from || 0 }} to {{ merchants.to || 0 }} of {{ merchants.total }} merchants
                </span>
                <div class="flex items-center gap-1.5">
                    <Link
                        v-for="link in merchants.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        :class="[
                            'px-3 py-1.5 rounded-lg text-xs font-bold border transition-all',
                            link.active ? 'bg-primary text-black border-primary' : link.url ? 'bg-card border-border text-foreground hover:bg-muted' : 'bg-card/50 border-border/50 text-muted-foreground/40 cursor-not-allowed'
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <!-- Add Merchant Modal -->
        <Dialog :open="isCreateOpen" @update:open="isCreateOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg text-foreground">Create New Merchant</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Register a merchant system to receive an automatically issued API token.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="createMerchant" class="space-y-4 py-2">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Merchant Name</label>
                        <Input
                            v-model="form.name"
                            type="text"
                            required
                            placeholder="e.g. Bazar Online, Invoice System"
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Webhook URL (Callback)</label>
                        <Input
                            v-model="form.webhook_url"
                            type="url"
                            placeholder="https://merchant.com/api/payment-callback"
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                        <p v-if="form.errors.webhook_url" class="text-xs text-destructive">{{ form.errors.webhook_url }}</p>
                    </div>

                    <div class="flex items-center space-x-2 pt-2">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            id="merchant_active"
                            class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary"
                        />
                        <label for="merchant_active" class="text-xs font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                            Active & Enabled
                        </label>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isCreateOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span>Save Merchant</span>
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Edit Merchant Modal -->
        <Dialog :open="isEditOpen" @update:open="isEditOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg text-foreground">Edit Merchant</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Update callback URL and merchant details.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="updateMerchant" class="space-y-4 py-2">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Merchant Name</label>
                        <Input
                            v-model="form.name"
                            type="text"
                            required
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Webhook URL</label>
                        <Input
                            v-model="form.webhook_url"
                            type="url"
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                    </div>

                    <div class="flex items-center space-x-2 pt-2">
                        <input
                            v-model="form.is_active"
                            type="checkbox"
                            id="edit_merchant_active"
                            class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary"
                        />
                        <label for="edit_merchant_active" class="text-xs font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                            Active & Enabled
                        </label>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isEditOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span>Update Changes</span>
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Test Webhook Modal -->
        <Dialog :open="isTestOpen" @update:open="isTestOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-lg">
                <DialogHeader>
                    <DialogTitle class="text-lg text-foreground flex items-center gap-2 font-bold">
                        <Activity class="h-5 w-5 text-blue-500 animate-pulse" />
                        Test Webhook: {{ testingMerchant?.name }}
                    </DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Send a secure mock transaction callback payload to verify your endpoint response and signature calculation.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4 py-3">
                    <div class="bg-muted p-3.5 rounded-lg space-y-1.5 border border-border">
                        <div class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Webhook Target URL</div>
                        <div class="font-mono text-xs text-foreground break-all flex items-center gap-1.5">
                            <span class="bg-blue-500/20 text-blue-500 border border-blue-500/20 px-1.5 py-0.5 rounded font-bold uppercase text-[10px]">POST</span>
                            <span v-if="testingMerchant?.webhook_url" class="text-foreground/90 font-medium">{{ testingMerchant.webhook_url }}</span>
                            <span v-else class="text-destructive font-medium italic">No Webhook URL Configured</span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-muted-foreground">Signs payload with merchant's active API Key.</span>
                        <Button 
                            type="button"
                            @click="runWebhookTest" 
                            :disabled="isTestingInProgress || !testingMerchant?.webhook_url"
                            class="bg-blue-600 text-white hover:bg-blue-500 font-bold px-4 h-9 flex items-center gap-2"
                        >
                            <Loader2 v-if="isTestingInProgress" class="h-4 w-4 animate-spin" />
                            <span v-else>Send Test Payload</span>
                        </Button>
                    </div>

                    <!-- Result Section -->
                    <div v-if="isTestingInProgress" class="py-12 flex flex-col items-center justify-center gap-3">
                        <Loader2 class="h-8 w-8 animate-spin text-blue-500" />
                        <span class="text-xs text-muted-foreground animate-pulse font-medium">Sending mock payload and waiting for response...</span>
                    </div>

                    <div v-else-if="testResult" class="space-y-4 border-t border-border pt-4">
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-muted/55 p-3 rounded-lg border border-border/80">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground block mb-0.5">Connection Status</span>
                                <span :class="['text-xs font-bold uppercase', testResult.status_code ? 'text-emerald-500' : 'text-destructive']">
                                    {{ testResult.status_code ? 'Connected' : 'Connection Failed' }}
                                </span>
                            </div>

                            <div class="bg-muted/55 p-3 rounded-lg border border-border/80">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground block mb-0.5">HTTP Status Code</span>
                                <span :class="['text-xs font-mono font-bold', testResult.success ? 'text-emerald-500' : 'text-destructive']">
                                    {{ testResult.status_code ?? '0' }}
                                </span>
                            </div>
                        </div>

                        <div class="bg-muted/55 p-3.5 rounded-lg border border-border/80 space-y-1">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground block">Verification Result / Notes</span>
                            <span :class="['text-xs font-medium leading-relaxed block', testResult.success ? 'text-emerald-400' : 'text-yellow-500']">
                                {{ testResult.notes ?? testResult.message }}
                            </span>
                        </div>

                        <div v-if="testResult.response_body !== undefined" class="space-y-1.5">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Response Body</span>
                            <pre class="bg-muted p-3.5 rounded-lg border border-border text-[11px] font-mono overflow-x-auto max-h-[150px] text-foreground/90 font-medium">{{ testResult.response_body || '(Empty Response)' }}</pre>
                        </div>
                    </div>
                </div>

                <DialogFooter class="border-t border-border pt-4">
                    <Button type="button" variant="secondary" @click="isTestOpen = false" class="bg-muted text-foreground border-none">
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
