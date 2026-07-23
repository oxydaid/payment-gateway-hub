<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { 
    Clock, 
    Copy, 
    CheckCircle2, 
    AlertCircle, 
    ArrowRight, 
    ExternalLink, 
    QrCode, 
    CreditCard, 
    ShieldCheck 
} from '@lucide/vue';

interface TransactionDetail {
    reference_id: string;
    merchant_name: string;
    merchant_ref_id: string;
    amount: number;
    fee: number;
    total_amount: number;
    status: 'PENDING' | 'PAID' | 'DONE' | 'FAILED' | 'EXPIRED' | 'REFUNDED';
    checkout_url: string | null;
    qris_url: string | null;
    payment_code: string | null;
    redirect_url: string | null;
    expired_at: string;
    payment_method: {
        name: string;
        type: 'va' | 'qris' | 'ewallet' | 'retail' | 'credit_card';
        gateway_name: string;
        gateway_icon: string | null;
        icon: string | null;
    } | null;
}

const props = defineProps<{
    transaction: TransactionDetail;
}>();

const copied = ref(false);
const remainingTime = ref('');
const redirectCountdown = ref(5);
let timerInterval: any = null;
let pollInterval: any = null;
let redirectInterval: any = null;

// Copy text function
function copyText(text: string) {
    navigator.clipboard.writeText(text);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}

// Calculate remaining time countdown
function updateRemainingTime() {
    const expiry = new Date(props.transaction.expired_at).getTime();
    const now = new Date().getTime();
    const diff = expiry - now;

    if (diff <= 0) {
        remainingTime.value = 'EXPIRED';
        clearInterval(timerInterval);
        return;
    }

    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

    remainingTime.value = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
}

// Poll transaction status using Inertia reload
function startPolling() {
    pollInterval = setInterval(() => {
        if (props.transaction.status === 'PENDING') {
            router.reload({
                only: ['transaction'],
                onSuccess: () => {
                    if (props.transaction.status === 'PAID' || props.transaction.status === 'DONE') {
                        clearInterval(pollInterval);
                        startRedirectCountdown();
                    }
                }
            });
        }
    }, 3000);
}

// Start 5 second redirect countdown
function startRedirectCountdown() {
    if (props.transaction.redirect_url) {
        redirectInterval = setInterval(() => {
            redirectCountdown.value--;
            if (redirectCountdown.value <= 0) {
                clearInterval(redirectInterval);
                window.location.href = props.transaction.redirect_url || '/';
            }
        }, 1000);
    }
}

// Manually trigger redirect
function manualRedirect() {
    if (props.transaction.redirect_url) {
        window.location.href = props.transaction.redirect_url;
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

onMounted(() => {
    if (props.transaction.status === 'PENDING') {
        updateRemainingTime();
        timerInterval = setInterval(updateRemainingTime, 1000);
        startPolling();
    } else if (props.transaction.status === 'PAID' || props.transaction.status === 'DONE') {
        startRedirectCountdown();
    }
});

onUnmounted(() => {
    clearInterval(timerInterval);
    clearInterval(pollInterval);
    clearInterval(redirectInterval);
});

const isSuccess = computed(() => {
    return props.transaction.status === 'PAID' || props.transaction.status === 'DONE';
});

const isFailed = computed(() => {
    return props.transaction.status === 'FAILED' || props.transaction.status === 'EXPIRED';
});
</script>

<template>
    <Head title="Secure Payment Checkout" />

    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between font-sans selection:bg-emerald-500/30">
        <!-- Top bar/header -->
        <header class="border-b border-slate-900 bg-slate-950/60 backdrop-blur-md sticky top-0 z-50 px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <ShieldCheck class="h-6 w-6 text-emerald-500" />
                <span class="font-extrabold text-sm uppercase tracking-widest text-slate-200">PG Bridge Checkout</span>
            </div>
            <div class="text-xs text-slate-400 font-medium">
                Ref: <code class="font-mono bg-slate-900 px-2 py-1 rounded text-slate-300">{{ transaction.reference_id.substring(0, 8) }}...</code>
            </div>
        </header>

        <!-- Main Card Area -->
        <main class="flex-1 max-w-lg w-full mx-auto p-4 md:p-6 flex flex-col justify-center">
            
            <!-- Success/Paid State -->
            <div v-if="isSuccess" class="bg-slate-900/60 border border-slate-800 p-8 rounded-2xl shadow-2xl text-center space-y-6 animate-in fade-in zoom-in-95 duration-300">
                <div class="h-20 w-20 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 rounded-full flex items-center justify-center mx-auto shadow-emerald-500/5 shadow-2xl">
                    <CheckCircle2 class="h-10 w-10 animate-bounce" />
                </div>
                <div class="space-y-2">
                    <h2 class="text-2xl font-black text-slate-100">Payment Successful!</h2>
                    <p class="text-slate-400 text-sm">Thank you, your transaction with <span class="font-bold text-slate-200">{{ transaction.merchant_name }}</span> has been completed successfully.</p>
                </div>

                <div class="bg-slate-950/50 border border-slate-800/60 rounded-xl p-4 text-left divide-y divide-slate-800/40 text-sm">
                    <div class="pb-3 flex justify-between">
                        <span class="text-slate-400">Order Reference</span>
                        <span class="font-semibold text-slate-200">{{ transaction.merchant_ref_id }}</span>
                    </div>
                    <div class="py-3 flex justify-between">
                        <span class="text-slate-400">Payment Method</span>
                        <span class="font-semibold text-slate-200">{{ transaction.payment_method?.name }}</span>
                    </div>
                    <div class="pt-3 flex justify-between">
                        <span class="text-slate-400">Total Paid</span>
                        <span class="font-black text-emerald-400">{{ formatIdr(transaction.total_amount) }}</span>
                    </div>
                </div>

                <div v-if="transaction.redirect_url" class="space-y-3 pt-2">
                    <p class="text-xs text-slate-400">Redirecting to merchant store in <span class="font-bold text-emerald-400">{{ redirectCountdown }}</span> seconds...</p>
                    <button 
                        @click="manualRedirect"
                        class="w-full h-12 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold rounded-xl transition-all flex items-center justify-center gap-2 cursor-pointer shadow-lg shadow-emerald-500/10"
                    >
                        Return to Store Now
                        <ArrowRight class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Failed/Expired State -->
            <div v-else-if="isFailed" class="bg-slate-900/60 border border-slate-800 p-8 rounded-2xl shadow-2xl text-center space-y-6">
                <div class="h-16 w-16 bg-rose-500/10 border border-rose-500/20 text-rose-400 rounded-full flex items-center justify-center mx-auto">
                    <AlertCircle class="h-8 w-8" />
                </div>
                <div class="space-y-2">
                    <h2 class="text-xl font-bold text-slate-100">Transaction Exceeded Expiry</h2>
                    <p class="text-slate-400 text-sm">The payment window for this reference has closed. Please request a new invoice from the merchant.</p>
                </div>
                
                <button 
                    v-if="transaction.redirect_url"
                    @click="manualRedirect"
                    class="w-full h-11 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl transition-all flex items-center justify-center gap-2 cursor-pointer"
                >
                    Back to Store
                </button>
            </div>

            <!-- Pending/Paying State -->
            <div v-else class="space-y-6">
                <!-- Info Section Card -->
                <div class="bg-slate-900/60 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
                    <div class="p-6 border-b border-slate-850 flex items-center justify-between bg-slate-950/40">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 bg-slate-900 border border-slate-800 rounded-lg flex items-center justify-center p-1.5 overflow-hidden">
                                <img v-if="transaction.payment_method?.icon" :src="transaction.payment_method?.icon" class="h-full w-full object-contain" />
                                <CreditCard v-else class="h-5 w-5 text-slate-400" />
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-200">{{ transaction.payment_method?.name }}</h3>
                                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">{{ transaction.payment_method?.gateway_name }} Gateway</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-amber-400 text-xs font-semibold">
                            <Clock class="h-3.5 w-3.5" />
                            <span>{{ remainingTime }}</span>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        <!-- Amount Section -->
                        <div class="text-center space-y-1">
                            <span class="text-xs text-slate-400 uppercase tracking-widest font-bold">Total Payment Amount</span>
                            <div class="text-3xl font-black text-slate-100 tracking-tight">{{ formatIdr(transaction.total_amount) }}</div>
                            <div class="text-[10px] text-slate-500">Includes secure payment gateway convenience fees</div>
                        </div>

                        <!-- Payment Method Type Output -->
                        <!-- 1. QRIS Rendering -->
                        <div v-if="transaction.payment_method?.type === 'qris'" class="space-y-4">
                            <div class="bg-white p-4 rounded-xl max-w-[240px] mx-auto shadow-inner flex flex-col items-center">
                                <img v-if="transaction.qris_url" :src="transaction.qris_url" alt="QRIS QR Code" class="w-full aspect-square object-contain" />
                                <div v-else class="w-full aspect-square bg-slate-100 flex items-center justify-center text-slate-400">
                                    <QrCode class="h-10 w-10 animate-pulse" />
                                </div>
                                <div class="mt-2 text-slate-900 font-black text-sm tracking-wider uppercase flex items-center gap-1">
                                    <span>QRIS</span>
                                    <span class="text-[9px] bg-slate-200 px-1 py-0.5 rounded text-slate-700 font-bold font-mono">GPN</span>
                                </div>
                            </div>
                            <p class="text-center text-xs text-slate-400 max-w-xs mx-auto">Scan the QR code above using your mobile banking or e-wallet application (GoPay, OVO, Dana, ShopeePay, LinkAja, BCA, etc.) to complete transaction.</p>
                        </div>

                        <!-- 2. Virtual Account or Text Code Code Rendering -->
                        <div v-else-if="transaction.payment_method?.type === 'va' || transaction.payment_code" class="space-y-3">
                            <div class="bg-slate-950/80 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-4">
                                <div class="space-y-1">
                                    <span class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold">Virtual Account Code</span>
                                    <div class="text-xl font-bold font-mono text-slate-200 tracking-wider">
                                        {{ transaction.payment_code || 'Retrieving code...' }}
                                    </div>
                                </div>
                                <button 
                                    v-if="transaction.payment_code"
                                    @click="copyText(transaction.payment_code)"
                                    class="h-9 px-3.5 bg-slate-900 hover:bg-slate-800 border border-slate-800 rounded-lg text-slate-300 font-semibold text-xs flex items-center gap-1.5 cursor-pointer active:scale-95 transition-transform"
                                >
                                    <Copy class="h-3.5 w-3.5" />
                                    <span>{{ copied ? 'Copied' : 'Copy' }}</span>
                                </button>
                            </div>
                            <div class="text-center py-1">
                                <p class="text-xs text-slate-400">Copy the Virtual Account number above, enter it into your bank app's transfer menu, and transfer the exact total amount.</p>
                            </div>
                        </div>

                        <!-- 3. E-Wallet / Direct Redirect Payment URL (Midtrans SNAP etc) -->
                        <div v-else-if="transaction.checkout_url" class="space-y-4">
                            <div class="text-center space-y-2">
                                <p class="text-xs text-slate-400">This payment method requires authentication. Click the button below to proceed to the secure transaction portal.</p>
                                <a 
                                    :href="transaction.checkout_url"
                                    target="_blank"
                                    class="w-full h-12 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold rounded-xl transition-all flex items-center justify-center gap-2 cursor-pointer shadow-lg shadow-emerald-500/10"
                                >
                                    Proceed to Payment Page
                                    <ExternalLink class="h-4 w-4" />
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Accordion/Payment Instructions -->
                <div class="bg-slate-900/40 border border-slate-900 rounded-xl p-4 text-xs space-y-3">
                    <h4 class="font-bold text-slate-300 uppercase tracking-wider text-[10px]">Payment Instructions</h4>
                    <ul class="list-disc pl-4 space-y-1.5 text-slate-400">
                        <li>Make sure the amount you transfer matches down to the last digit.</li>
                        <li>Payments are processed automatically. Do not close this page until payment is verified.</li>
                        <li>If you experience any issues, please contact merchant store with order reference ID.</li>
                    </ul>
                </div>
            </div>

        </main>

        <!-- Footer -->
        <footer class="py-6 text-center border-t border-slate-900 bg-slate-950/20 text-[10px] text-slate-600 font-medium">
            <p>&copy; {{ new Date().getFullYear() }} PG Bridge Payment Solution. All rights reserved.</p>
            <p class="mt-1">Secured with End-to-End Encryption & SHA-256 Signature Verification.</p>
        </footer>
    </div>
</template>
