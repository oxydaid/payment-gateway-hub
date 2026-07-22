<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { 
    Search, 
    Filter, 
    RefreshCw, 
    ChevronLeft, 
    ChevronRight,
    Eye,
    SlidersHorizontal
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface TransactionItem {
    id: number;
    reference_id: string;
    merchant_name: string;
    gateway_name: string;
    method_name: string;
    amount: number;
    fee: number;
    total_amount: number;
    status: 'PENDING' | 'PAID' | 'FAILED' | 'EXPIRED';
    created_at: string;
}

interface PaginatedTransactions {
    data: TransactionItem[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number;
    to: number;
    last_page: number;
}

interface GatewayOption {
    id: number;
    name: string;
}

const props = defineProps<{
    transactions: PaginatedTransactions;
    gateways: GatewayOption[];
    filters: {
        search?: string;
        status?: string;
        gateway_id?: string;
    };
}>();

const searchTerm = ref(props.filters.search || '');
const filterStatus = ref(props.filters.status || '');
const filterGateway = ref(props.filters.gateway_id || '');

function applyFilters() {
    router.get('/transactions', {
        search: searchTerm.value,
        status: filterStatus.value,
        gateway_id: filterGateway.value,
    }, {
        preserveState: true,
        replace: true,
    });
}

function resetFilters() {
    searchTerm.value = '';
    filterStatus.value = '';
    filterGateway.value = '';
    applyFilters();
}

function formatIdr(value: number): string {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(value);
}

function formatDate(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', { 
        day: '2-digit', 
        month: 'short', 
        year: 'numeric',
        hour: '2-digit', 
        minute: '2-digit' 
    });
}

function getStatusClass(status: string): string {
    switch (status) {
        case 'PAID':
            return 'bg-primary/10 text-primary border-primary/20';
        case 'PENDING':
            return 'bg-yellow-500/10 text-yellow-500 border-yellow-500/20';
        case 'FAILED':
        case 'EXPIRED':
            return 'bg-destructive/10 text-destructive border-destructive/20';
        default:
            return 'bg-muted text-muted-foreground border-border';
    }
}
</script>

<template>
    <Head title="Transaction Logs & Audits" />

    <AdminLayout title="Transactions">
        <!-- Filter Controls Container -->
        <div class="bg-card border border-border p-6 rounded-xl shadow-xl mb-6">
            <form @submit.prevent="applyFilters" class="grid gap-4 md:grid-cols-4 items-end">
                <!-- Search Input -->
                <div class="space-y-2 md:col-span-2">
                    <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Search</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-muted-foreground">
                            <Search class="h-4 w-4" />
                        </span>
                        <Input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search reference, merchant..."
                            class="pl-10 bg-muted border-none text-foreground placeholder:text-muted-foreground focus-visible:ring-primary focus-visible:ring-1"
                        />
                    </div>
                </div>

                <!-- Status Select -->
                <div class="space-y-2">
                    <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Status</label>
                    <select
                        v-model="filterStatus"
                        class="w-full h-10 px-3 rounded-md bg-muted text-foreground border-none text-sm focus:ring-primary focus:ring-1 focus:outline-none"
                    >
                        <option value="">All Statuses</option>
                        <option value="PENDING">PENDING</option>
                        <option value="PAID">PAID</option>
                        <option value="FAILED">FAILED</option>
                        <option value="EXPIRED">EXPIRED</option>
                    </select>
                </div>

                <!-- Gateway Select -->
                <div class="space-y-2">
                    <label class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Payment Gateway</label>
                    <select
                        v-model="filterGateway"
                        class="w-full h-10 px-3 rounded-md bg-muted text-foreground border-none text-sm focus:ring-primary focus:ring-1 focus:outline-none"
                    >
                        <option value="">All Gateways</option>
                        <option 
                            v-for="gw in gateways" 
                            :key="gw.id" 
                            :value="gw.id"
                        >
                            {{ gw.name }}
                        </option>
                    </select>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-2 md:col-span-4 justify-end pt-2">
                    <Button 
                        type="submit" 
                        class="bg-primary hover:opacity-90 text-black font-bold h-10 px-6 rounded-lg transition-transform hover:scale-[1.02] active:scale-[0.98]"
                    >
                        Filter
                    </Button>
                    <Button 
                        type="button" 
                        variant="secondary"
                        @click="resetFilters" 
                        class="px-4 border border-border bg-muted text-muted-foreground hover:text-foreground h-10 rounded-lg"
                    >
                        <RefreshCw class="h-4 w-4" />
                    </Button>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="bg-card border border-border rounded-xl shadow-xl overflow-hidden mb-6">
            <div class="overflow-x-auto w-full">
                <table class="w-full text-left border-collapse min-w-[850px] whitespace-nowrap">
                    <thead>
                        <tr class="text-xs font-bold uppercase tracking-wider text-muted-foreground border-b border-border whitespace-nowrap">
                            <th class="px-6 py-4">Reference ID</th>
                            <th class="px-6 py-4">Merchant Name</th>
                            <th class="px-6 py-4">Gateway</th>
                            <th class="px-6 py-4">Payment Method</th>
                            <th class="px-6 py-4">Amount</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr 
                            v-for="tx in transactions.data" 
                            :key="tx.id"
                            class="hover:bg-muted/50 transition-colors group text-sm text-muted-foreground whitespace-nowrap"
                        >
                            <td class="px-6 py-4 font-mono font-semibold text-foreground">{{ tx.reference_id }}</td>
                            <td class="px-6 py-4 text-foreground font-medium">{{ tx.merchant_name }}</td>
                            <td class="px-6 py-4">
                                <span class="capitalize">{{ tx.gateway_name }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="bg-muted text-foreground px-2.5 py-0.5 rounded text-xs uppercase font-bold border border-border">
                                    {{ tx.method_name }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-foreground font-bold">{{ formatIdr(tx.total_amount) }}</td>
                            <td class="px-6 py-4">
                                <span :class="['px-2.5 py-0.5 rounded-full text-xs font-bold border', getStatusClass(tx.status)]">
                                    {{ tx.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs">{{ formatDate(tx.created_at) }}</td>
                            <td class="px-6 py-4 text-right">
                                <Link 
                                    :href="`/transactions/${tx.id}`"
                                    class="inline-flex items-center justify-center p-2 rounded-lg bg-muted text-foreground hover:text-black hover:bg-primary transition-all hover:scale-105"
                                >
                                    <Eye class="h-4 w-4" />
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="transactions.data.length === 0">
                            <td colspan="8" class="text-center py-16 text-muted-foreground italic text-sm">
                                No transactions match your filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Controls -->
        <div v-if="transactions.last_page > 1" class="flex flex-col sm:flex-row items-center justify-between gap-4 py-4 px-1">
            <span class="text-xs text-muted-foreground">
                Showing {{ transactions.from || 0 }} to {{ transactions.to || 0 }} of {{ transactions.total }} transactions
            </span>
            <div class="flex items-center gap-1.5">
                <Link
                    v-for="link in transactions.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    :class="[
                        'px-3.5 py-1.5 rounded-lg text-xs font-bold border transition-all',
                        link.active ? 'bg-primary text-black border-primary' : link.url ? 'bg-card border-border text-foreground hover:bg-muted' : 'bg-card/50 border-border/50 text-muted-foreground/40 cursor-not-allowed'
                    ]"
                    v-html="link.label"
                />
            </div>
        </div>
    </AdminLayout>
</template>
