<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { 
    ChevronLeft, 
    Calendar, 
    ArrowRightLeft, 
    CreditCard, 
    User, 
    CheckCircle,
    Clock,
    XCircle,
    Info,
    Code,
    Send,
    Loader2
} from '@lucide/vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

interface WebhookLogItem {
    id: number;
    direction: string;
    payload: Record<string, any>;
    status_code: number;
    notes: string | null;
    created_at: string;
}

interface TransactionDetails {
    id: number;
    reference_id: string;
    merchant_ref_id: string;
    merchant_name: string;
    gateway_name: string;
    method_name: string;
    amount: number;
    fee: number;
    total_amount: number;
    status: 'PENDING' | 'PAID' | 'DONE' | 'FAILED' | 'EXPIRED';
    pg_status: string | null;
    checkout_url: string | null;
    qris_url: string | null;
    pg_ref_id: string | null;
    pg_response: Record<string, any> | null;
    expired_at: string;
    created_at: string;
    webhook_logs: WebhookLogItem[];
}

const props = defineProps<{
    transaction: TransactionDetails;
}>();

const activeTab = ref<'details' | 'webhooks' | 'raw'>('details');
const isResending = ref(false);

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
        minute: '2-digit',
        second: '2-digit'
    });
}

function getStatusClass(status: string): string {
    switch (status) {
        case 'DONE':
            return 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20';
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

function resendWebhook() {
    isResending.value = true;
    router.post(`/transactions/${props.transaction.id}/resend-webhook`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isResending.value = false;
        }
    });
}
</script>

<template>
    <Head :title="`Transaction details - ${transaction.reference_id}`" />

    <AdminLayout title="Transaction Log Detail">
        <div class="space-y-6">
            <!-- Navigation Back & Title -->
            <div class="flex items-center justify-between flex-wrap gap-4">
                <Link 
                    href="/transactions"
                    class="inline-flex items-center text-xs font-bold text-muted-foreground hover:text-foreground uppercase tracking-wider gap-1 transition-colors"
                >
                    <ChevronLeft class="h-4 w-4" />
                    Back to Logs
                </Link>

                <div class="flex items-center gap-3">
                    <button 
                        @click="resendWebhook"
                        :disabled="isResending"
                        class="bg-muted text-foreground border border-border hover:bg-muted/80 h-9 px-4 rounded-lg flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider cursor-pointer disabled:opacity-50"
                        title="Resend webhook notification payload manually to merchant callback URL"
                    >
                        <Loader2 v-if="isResending" class="h-3.5 w-3.5 animate-spin text-primary" />
                        <Send v-else class="h-3.5 w-3.5" />
                        <span>{{ isResending ? 'Resending...' : 'Resend Webhook' }}</span>
                    </button>

                    <div class="flex items-center gap-1.5 bg-card p-1 rounded-lg border border-border">
                        <button 
                            @click="activeTab = 'details'"
                            :class="[
                                'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all',
                                activeTab === 'details' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground'
                            ]"
                        >
                            Billing Details
                        </button>
                        <button 
                            @click="activeTab = 'webhooks'"
                            :class="[
                                'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all',
                                activeTab === 'webhooks' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground'
                            ]"
                        >
                            Merchant Webhooks ({{ transaction.webhook_logs.length }})
                        </button>
                        <button 
                            @click="activeTab = 'raw'"
                            :class="[
                                'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all',
                                activeTab === 'raw' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground'
                            ]"
                        >
                            Raw Payload
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab 1: Billing details -->
            <div v-if="activeTab === 'details'" class="grid gap-6 md:grid-cols-3">
                <!-- Info Summary -->
                <Card class="bg-card border-border shadow-lg md:col-span-2 min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">Billing Overview</CardTitle>
                    </CardHeader>
                    <CardContent class="p-5 space-y-5">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Reference ID</span>
                                <p class="text-sm font-bold text-foreground font-mono mt-0.5">{{ transaction.reference_id }}</p>
                            </div>
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Merchant Ref (Invoice)</span>
                                <p class="text-sm font-bold text-foreground font-mono mt-0.5">{{ transaction.merchant_ref_id }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 border-t border-border pt-4">
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Merchant Client</span>
                                <p class="text-sm font-bold text-foreground mt-0.5 flex items-center gap-1.5">
                                    <User class="h-4 w-4 text-primary" />
                                    {{ transaction.merchant_name }}
                                </p>
                            </div>
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Channel / Method</span>
                                <p class="mt-0.5">
                                    <span class="bg-muted px-2.5 py-0.5 rounded text-xs border border-border font-bold text-foreground">
                                        {{ transaction.method_name }}
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 border-t border-border pt-4">
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Payment Gateway</span>
                                <p class="text-sm font-bold text-foreground capitalize mt-0.5 flex items-center gap-1.5">
                                    <CreditCard class="h-4 w-4 text-primary" />
                                    {{ transaction.gateway_name }}
                                </p>
                            </div>
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Gateway Status</span>
                                <div class="mt-0.5">
                                    <span class="px-2.5 py-0.5 rounded text-xs border border-border bg-muted font-bold text-foreground font-mono uppercase">
                                        {{ transaction.pg_status || 'PENDING' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 border-t border-border pt-4">
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Settlement Status (Bridge)</span>
                                <div class="mt-0.5">
                                    <span :class="['px-2.5 py-0.5 rounded-full text-xs font-bold border', getStatusClass(transaction.status)]">
                                        {{ transaction.status }}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Created Date</span>
                                <p class="text-xs text-foreground mt-0.5 flex items-center gap-1.5">
                                    <Calendar class="h-4 w-4 text-muted-foreground" />
                                    {{ formatDate(transaction.created_at) }}
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 border-t border-border pt-4">
                            <div>
                                <span class="text-xs text-muted-foreground uppercase font-semibold">Expiry Date</span>
                                <p class="text-xs text-foreground mt-0.5 flex items-center gap-1.5">
                                    <Clock class="h-4 w-4 text-muted-foreground" />
                                    {{ formatDate(transaction.expired_at) }}
                                </p>
                            </div>
                            <div>
                                <!-- Empty -->
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Cost breakdown card -->
                <Card class="bg-card border-border shadow-lg min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">Amount Pricing</CardTitle>
                    </CardHeader>
                    <CardContent class="p-5 space-y-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-muted-foreground">Subtotal Amount</span>
                            <span class="font-medium text-foreground">{{ formatIdr(transaction.amount) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm border-b border-border pb-3">
                            <span class="text-muted-foreground">Admin/Gateway Fee</span>
                            <span class="font-medium text-foreground">+ {{ formatIdr(transaction.fee) }}</span>
                        </div>
                        <div class="flex justify-between items-center pt-2">
                            <span class="text-sm font-bold text-foreground">Total Bill</span>
                            <span class="text-lg font-extrabold text-primary">{{ formatIdr(transaction.total_amount) }}</span>
                        </div>

                        <!-- Action redirect links if pending -->
                        <div v-if="transaction.status === 'PENDING'" class="pt-6 space-y-2">
                            <a 
                                v-if="transaction.checkout_url"
                                :href="transaction.checkout_url"
                                target="_blank"
                                class="w-full bg-primary hover:opacity-90 text-black text-center font-bold text-xs uppercase tracking-wider h-10 rounded-lg flex items-center justify-center gap-1.5"
                            >
                                Checkout Link
                                <Info class="h-4 w-4" />
                            </a>
                            <div v-if="transaction.qris_url" class="border border-border p-3 rounded-lg flex flex-col items-center bg-muted/40">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground mb-2">QRIS URL Code</span>
                                <a :href="transaction.qris_url" target="_blank" class="text-xs font-bold text-primary hover:underline truncate max-w-full">
                                    {{ transaction.qris_url }}
                                </a>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Tab 2: Webhooks outgoing logs -->
            <div v-if="activeTab === 'webhooks'" class="space-y-4">
                <div v-if="transaction.webhook_logs.length === 0" class="bg-card border border-border rounded-xl p-12 text-center text-muted-foreground italic">
                    No webhooks dispatched to merchant callback yet.
                </div>

                <div 
                    v-for="(log, idx) in transaction.webhook_logs" 
                    :key="log.id"
                    class="bg-card border border-border rounded-xl overflow-hidden shadow-md min-w-0"
                >
                    <div class="bg-muted/40 px-6 py-4 flex items-center justify-between border-b border-border flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-foreground">Dispatch #{{ idx + 1 }}</span>
                            <span 
                                :class="[
                                    'px-2 py-0.5 rounded text-xs font-bold border', 
                                    log.status_code >= 200 && log.status_code < 300 
                                        ? 'bg-primary/10 text-primary border-primary/20' 
                                        : 'bg-destructive/10 text-destructive border-destructive/20'
                                ]"
                            >
                                HTTP {{ log.status_code }}
                            </span>
                        </div>
                        <span class="text-xs text-muted-foreground flex items-center gap-1">
                            <Send class="h-3.5 w-3.5" />
                            Sent: {{ formatDate(log.created_at) }}
                        </span>
                    </div>

                    <div class="p-5 grid gap-5 md:grid-cols-2">
                        <!-- Payload outgoing -->
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Outgoing Payload</h4>
                            <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-xs font-mono text-foreground">{{ JSON.stringify(log.payload, null, 2) }}</pre>
                        </div>
                        <!-- Response body -->
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-2">Merchant Response Body / Details</h4>
                            <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-xs font-mono text-foreground">{{ log.notes || '(Empty Response)' }}</pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 3: PG Raw Response -->
            <div v-if="activeTab === 'raw'">
                <Card class="bg-card border-border shadow-lg min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">Payment Gateway Response Logs</CardTitle>
                    </CardHeader>
                    <CardContent class="p-5 space-y-4">
                        <div>
                            <span class="text-xs text-muted-foreground uppercase font-bold">PG External Reference ID</span>
                            <p class="font-mono text-sm font-bold text-foreground mt-0.5">{{ transaction.pg_ref_id || 'Not generated' }}</p>
                        </div>

                        <div class="border-t border-border pt-4">
                            <span class="text-xs text-muted-foreground uppercase font-bold mb-2 block">Raw PG Pay Response JSON</span>
                            <div v-if="transaction.pg_response" class="bg-muted p-5 rounded-lg border border-border overflow-x-auto">
                                <pre class="text-xs font-mono text-foreground">{{ JSON.stringify(transaction.pg_response, null, 2) }}</pre>
                            </div>
                            <p v-else class="text-sm text-muted-foreground italic">No response payload logs saved from gateway.</p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
