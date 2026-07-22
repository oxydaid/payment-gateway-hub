<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { 
    Plus, 
    Copy, 
    Check, 
    Key, 
    Trash2, 
    Edit2,
    ShieldCheck, 
    Loader2, 
    Globe, 
    Building2 
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

interface ApiKeyItem {
    id: number;
    name: string;
    key: string;
    is_active: boolean;
    merchant_name: string;
    last_used_at: string | null;
    created_at: string;
}

interface MerchantOption {
    id: number;
    name: string;
}

const props = defineProps<{
    api_keys: ApiKeyItem[];
    merchants: MerchantOption[];
}>();

const apiKeysList = ref(props.api_keys.map(k => ({ ...k })));

watch(() => props.api_keys, (newKeys) => {
    apiKeysList.value = newKeys.map(k => ({ ...k }));
}, { deep: true });

const isCreateOpen = ref(false);
const isEditOpen = ref(false);
const currentKey = ref<ApiKeyItem | null>(null);
const copiedKeyId = ref<number | null>(null);

const form = useForm({
    name: '',
    merchant_id: null as number | null,
});

const editForm = useForm({
    name: '',
    is_active: true,
});

function openCreate() {
    form.reset();
    form.clearErrors();
    isCreateOpen.value = true;
}

function createKey() {
    form.post('/api-keys', {
        onSuccess: () => {
            isCreateOpen.value = false;
        }
    });
}

function openEdit(key: ApiKeyItem) {
    currentKey.value = key;
    editForm.name = key.name;
    editForm.is_active = key.is_active;
    editForm.clearErrors();
    isEditOpen.value = true;
}

function submitEdit() {
    if (!currentKey.value) return;
    editForm.put(`/api-keys/${currentKey.value.id}`, {
        onSuccess: () => {
            isEditOpen.value = false;
        }
    });
}

function deleteKey(key: ApiKeyItem) {
    if (confirm(`Revoke API Key "${key.name}"? Applications using this token will be denied access.`)) {
        router.delete(`/api-keys/${key.id}`, { preserveScroll: true });
    }
}

function copyToClipboard(text: string, id: number) {
    navigator.clipboard.writeText(text);
    copiedKeyId.value = id;
    setTimeout(() => {
        copiedKeyId.value = null;
    }, 2000);
}

function formatDate(isoString: string | null): string {
    if (!isoString) return 'Never';
    const d = new Date(isoString);
    return d.toLocaleDateString('id-ID', { 
        day: '2-digit', 
        month: 'short', 
        hour: '2-digit', 
        minute: '2-digit' 
    });
}
</script>

<template>
    <Head title="API Keys Authentication" />

    <AdminLayout title="API Keys">
        <div class="space-y-6 max-w-full">
            <!-- Header Banner -->
            <div class="bg-card border border-border p-6 rounded-xl shadow-lg flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <h2 class="text-lg font-bold text-foreground">System API Keys</h2>
                    <p class="text-sm text-muted-foreground">
                        Manage Bearer Authentication tokens for global application access or individual merchant integrations.
                    </p>
                </div>

                <Button
                    @click="openCreate"
                    class="bg-primary hover:opacity-90 text-black font-bold uppercase tracking-wider h-10 px-5 rounded-lg flex items-center gap-2"
                >
                    <Plus class="h-4 w-4" />
                    Generate Key
                </Button>
            </div>

            <!-- API Keys Table (Responsive Container) -->
            <div class="bg-card border border-border rounded-xl shadow-xl overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="w-full text-left border-collapse min-w-[750px] whitespace-nowrap">
                        <thead>
                            <tr class="text-xs font-bold uppercase tracking-wider text-muted-foreground border-b border-border whitespace-nowrap">
                                <th class="px-6 py-4">Key Label</th>
                                <th class="px-6 py-4">Scope / Merchant</th>
                                <th class="px-6 py-4">Token Secret</th>
                                <th class="px-6 py-4">Last Used</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="k in apiKeysList"
                                :key="k.id"
                                class="hover:bg-muted/50 transition-colors text-sm text-muted-foreground whitespace-nowrap"
                            >
                                <td class="px-6 py-4 font-bold text-foreground flex items-center gap-2">
                                    <Key class="h-4 w-4 text-primary" />
                                    {{ k.name }}
                                </td>
                                <td class="px-6 py-4">
                                    <span 
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-xs font-bold border inline-flex items-center gap-1.5',
                                            k.merchant_name.includes('Global') 
                                                ? 'bg-purple-500/10 text-purple-500 border-purple-500/20' 
                                                : 'bg-blue-500/10 text-blue-500 border-blue-500/20'
                                        ]"
                                    >
                                        <Globe v-if="k.merchant_name.includes('Global')" class="h-3 w-3" />
                                        <Building2 v-else class="h-3 w-3" />
                                        {{ k.merchant_name }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <code class="bg-muted text-foreground px-2 py-1 rounded text-xs font-mono font-bold border border-border">
                                            {{ k.key.substring(0, 16) }}••••••••••••
                                        </code>
                                        <button
                                            @click="copyToClipboard(k.key, k.id)"
                                            class="p-1 text-muted-foreground hover:text-primary transition-colors cursor-pointer"
                                            title="Copy Token Secret"
                                        >
                                            <Check v-if="copiedKeyId === k.id" class="h-4 w-4 text-primary" />
                                            <Copy v-else class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-xs font-mono">{{ formatDate(k.last_used_at) }}</td>
                                <td class="px-6 py-4">
                                    <span 
                                        :class="[
                                            'px-2.5 py-0.5 rounded text-[10px] font-bold border', 
                                            k.is_active 
                                                ? 'bg-primary/10 text-primary border-primary/20' 
                                                : 'bg-destructive/10 text-destructive border-destructive/20'
                                        ]"
                                    >
                                        {{ k.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            @click="openEdit(k)"
                                            class="p-1.5 rounded-md bg-muted text-muted-foreground hover:bg-primary hover:text-black transition-colors cursor-pointer border border-border"
                                            title="Edit Token"
                                        >
                                            <Edit2 class="h-4 w-4" />
                                        </button>
                                        <button
                                            @click="deleteKey(k)"
                                            class="p-1.5 rounded-md bg-muted text-destructive hover:bg-destructive hover:text-white transition-colors cursor-pointer border border-border"
                                            title="Revoke Token"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="apiKeysList.length === 0">
                                <td colspan="6" class="text-center py-12 text-muted-foreground italic text-sm">
                                    No API Keys found. Click "Generate Key" above to create one.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Add API Key Modal -->
        <Dialog :open="isCreateOpen" @update:open="isCreateOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg text-foreground">Generate API Token</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Create a secure Bearer token for accessing payment creation endpoints.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="createKey" class="space-y-4 py-2">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Token Label</label>
                        <Input
                            v-model="form.name"
                            type="text"
                            required
                            placeholder="e.g. Production Server, Zapier Hook"
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                        <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Scope / Merchant Context</label>
                        <select
                            v-model="form.merchant_id"
                            class="w-full h-10 px-3 rounded-md bg-muted text-foreground border-none text-sm focus:ring-primary focus:ring-1 focus:outline-none"
                        >
                            <option :value="null">Global API Key (Access All Merchants)</option>
                            <option 
                                v-for="m in merchants" 
                                :key="m.id" 
                                :value="m.id"
                            >
                                Merchant: {{ m.name }}
                            </option>
                        </select>
                        <p class="text-[10px] text-muted-foreground mt-1">
                            Global keys allow transacting for any active merchant by providing merchant_id in request.
                        </p>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isCreateOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span>Create Token</span>
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Edit API Key Modal -->
        <Dialog :open="isEditOpen" @update:open="isEditOpen = $event">
            <DialogContent class="bg-card border-border text-foreground w-full max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg text-foreground">Edit API Token</DialogTitle>
                    <DialogDescription class="text-xs text-muted-foreground">
                        Modify API token name and active state.
                    </DialogDescription>
                </DialogHeader>

                <form @submit.prevent="submitEdit" class="space-y-4 py-2">
                    <div class="space-y-1">
                        <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Token Label</label>
                        <Input
                            v-model="editForm.name"
                            type="text"
                            required
                            placeholder="e.g. Production Server, Zapier Hook"
                            class="bg-muted border-none text-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                        <p v-if="editForm.errors.name" class="text-xs text-destructive">{{ editForm.errors.name }}</p>
                    </div>

                    <!-- Active State Checkbox -->
                    <div class="flex items-center space-x-2 pt-2">
                        <input
                            v-model="editForm.is_active"
                            type="checkbox"
                            id="edit_key_is_active"
                            class="h-4 w-4 rounded border-border bg-muted text-primary focus:ring-primary cursor-pointer"
                        />
                        <label for="edit_key_is_active" class="text-xs font-bold uppercase tracking-wider text-muted-foreground cursor-pointer">
                            Active
                        </label>
                    </div>

                    <DialogFooter class="pt-4 flex gap-2">
                        <Button type="button" variant="secondary" @click="isEditOpen = false" class="bg-muted text-foreground border-none">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="editForm.processing" class="bg-primary hover:opacity-90 text-black font-bold">
                            <Loader2 v-if="editForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            <span>Save Changes</span>
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
