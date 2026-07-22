<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { 
    TrendingUp, 
    DollarSign, 
    CheckCircle2, 
    Radio, 
    ArrowRight, 
    ExternalLink
} from '@lucide/vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

interface MetricProps {
    total_volume: number;
    total_fees: number;
    successful_count: number;
    active_gateways: number;
}

interface VolumeTrendItem {
    date: string;
    volume: number;
    count: number;
}

interface RecentTransaction {
    id: number;
    reference_id: string;
    merchant_name: string;
    gateway_name: string;
    method_name: string;
    total_amount: number;
    status: 'PENDING' | 'PAID' | 'FAILED' | 'EXPIRED';
    created_at: string;
}

const props = withDefaults(defineProps<{
    metrics: MetricProps;
    volume_trends?: VolumeTrendItem[];
    recent_transactions: RecentTransaction[];
}>(), {
    volume_trends: () => []
});

// Currency Formatter
function formatIdr(value: number): string {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(value);
}

// Format Short Date
function formatDate(dateStr: string): string {
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Status Badges
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

// SVG Area Chart Geometry calculations
const chartWidth = 800;
const chartHeight = 220;

const maxVolume = computed(() => {
    const max = Math.max(...props.volume_trends.map(t => Number(t.volume)), 1000);
    return max * 1.15; // 15% headroom
});

const points = computed(() => {
    const len = props.volume_trends.length;
    if (len === 0) return [];
    
    const stepX = chartWidth / (len - 1 || 1);
    
    return props.volume_trends.map((item, index) => {
        const x = index * stepX;
        const normalizedY = Number(item.volume) / maxVolume.value;
        const y = chartHeight - (normalizedY * (chartHeight - 40)) - 20;
        return { x, y, item };
    });
});

const linePath = computed(() => {
    if (points.value.length === 0) return '';
    return points.value.reduce((acc, pt, index) => {
        return index === 0 ? `M ${pt.x} ${pt.y}` : `${acc} L ${pt.x} ${pt.y}`;
    }, '');
});

const areaPath = computed(() => {
    if (points.value.length === 0) return '';
    const first = points.value[0];
    const last = points.value[points.value.length - 1];
    return `${linePath.value} L ${last.x} ${chartHeight} L ${first.x} ${chartHeight} Z`;
});

// Interactive tooltip on SVG
const hoveredPoint = ref<{ x: number; y: number; item: VolumeTrendItem } | null>(null);

function handleMouseMove(e: MouseEvent) {
    const svg = e.currentTarget as SVGSVGElement;
    const rect = svg.getBoundingClientRect();
    const clientX = e.clientX - rect.left;
    const svgX = (clientX / rect.width) * chartWidth;
    
    let closest = points.value[0];
    let minDiff = Math.abs(svgX - closest.x);
    
    for (let i = 1; i < points.value.length; i++) {
        const diff = Math.abs(svgX - points.value[i].x);
        if (diff < minDiff) {
            minDiff = diff;
            closest = points.value[i];
        }
    }
    
    hoveredPoint.value = closest;
}

function handleMouseLeave() {
    hoveredPoint.value = null;
}
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout title="Overview">
        <!-- KPI Cards Grid -->
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4 mb-8">
            <!-- Metric Card 1: Total Volume -->
            <Card class="bg-card border-border hover:bg-muted/50 transition-colors duration-200 shadow-lg">
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Total Volume</CardTitle>
                    <TrendingUp class="h-4 w-4 text-primary" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold tracking-tight text-foreground">{{ formatIdr(metrics.total_volume) }}</div>
                    <p class="text-xs text-muted-foreground mt-1">Accumulated successful payments</p>
                </CardContent>
            </Card>

            <!-- Metric Card 2: Total Fees -->
            <Card class="bg-card border-border hover:bg-muted/50 transition-colors duration-200 shadow-lg">
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Total Fees</CardTitle>
                    <DollarSign class="h-4 w-4 text-primary" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold tracking-tight text-foreground">{{ formatIdr(metrics.total_fees) }}</div>
                    <p class="text-xs text-muted-foreground mt-1">Platform service revenue</p>
                </CardContent>
            </Card>

            <!-- Metric Card 3: Success Count -->
            <Card class="bg-card border-border hover:bg-muted/50 transition-colors duration-200 shadow-lg">
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Paid Payments</CardTitle>
                    <CheckCircle2 class="h-4 w-4 text-primary" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold tracking-tight text-foreground">{{ metrics.successful_count }}</div>
                    <p class="text-xs text-muted-foreground mt-1">Successful transactions settled</p>
                </CardContent>
            </Card>

            <!-- Metric Card 4: Active Gateways -->
            <Card class="bg-card border-border hover:bg-muted/50 transition-colors duration-200 shadow-lg">
                <CardHeader class="flex flex-row items-center justify-between pb-2">
                    <CardTitle class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Active Channels</CardTitle>
                    <Radio class="h-4 w-4 text-primary" />
                </CardHeader>
                <CardContent>
                    <div class="text-2xl font-bold tracking-tight text-foreground">{{ metrics.active_gateways }}</div>
                    <p class="text-xs text-muted-foreground mt-1">Live integration endpoints</p>
                </CardContent>
            </Card>
        </div>

        <!-- Custom Area Chart Section -->
        <div class="bg-card border border-border rounded-xl p-6 mb-8 shadow-xl">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-3">
                <div>
                    <h3 class="text-lg font-bold text-foreground">Transaction History</h3>
                    <p class="text-xs text-muted-foreground">Paid volume activity over the last 30 days</p>
                </div>
                <div v-if="hoveredPoint" class="bg-muted border border-border px-3 py-1.5 rounded-lg text-xs flex gap-4">
                    <div>
                        <span class="text-muted-foreground">Date:</span> <strong class="text-foreground">{{ hoveredPoint.item.date }}</strong>
                    </div>
                    <div>
                        <span class="text-muted-foreground">Volume:</span> <strong class="text-primary">{{ formatIdr(hoveredPoint.item.volume) }}</strong>
                    </div>
                    <div>
                        <span class="text-muted-foreground">Count:</span> <strong class="text-foreground">{{ hoveredPoint.item.count }}</strong>
                    </div>
                </div>
                <div v-else class="text-xs text-muted-foreground italic">
                    Hover chart line to view daily metrics
                </div>
            </div>

            <!-- SVG Chart Box -->
            <div class="relative w-full overflow-hidden h-[240px]">
                <svg
                    :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
                    class="w-full h-full cursor-crosshair overflow-visible"
                    preserveAspectRatio="none"
                    @mousemove="handleMouseMove"
                    @mouseleave="handleMouseLeave"
                >
                    <defs>
                        <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--primary)" stop-opacity="0.3" />
                            <stop offset="100%" stop-color="var(--primary)" stop-opacity="0.0" />
                        </linearGradient>
                    </defs>

                    <!-- Horizontal Grid lines -->
                    <line :x1="0" :y1="chartHeight - 30" :x2="chartWidth" :y2="chartHeight - 30" stroke="currentColor" class="text-border" stroke-dasharray="4,4" />
                    <line :x1="0" :y1="(chartHeight - 30) / 2" :x2="chartWidth" :y2="(chartHeight - 30) / 2" stroke="currentColor" class="text-border" stroke-dasharray="4,4" />
                    <line :x1="0" :y1="30" :x2="chartWidth" :y2="30" stroke="currentColor" class="text-border" stroke-dasharray="4,4" />

                    <!-- Filled Area -->
                    <path :d="areaPath" fill="url(#areaGrad)" />

                    <!-- Vector Line Path -->
                    <path :d="linePath" fill="none" stroke="var(--primary)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>

                <!-- HTML Absolute Hover Indicator (100% round circles, never gepeng) -->
                <div v-if="hoveredPoint" class="absolute pointer-events-none inset-0">
                    <!-- Vertical Dashed Line -->
                    <div 
                        class="absolute top-0 bottom-0 w-px border-l border-dashed border-muted-foreground/60 -translate-x-1/2"
                        :style="{ left: `${(hoveredPoint.x / chartWidth) * 100}%` }"
                    ></div>
                    <!-- Outer Glow Ring -->
                    <div 
                        class="absolute h-5 w-5 rounded-full bg-primary/30 -translate-x-1/2 -translate-y-1/2 flex items-center justify-center"
                        :style="{ 
                            left: `${(hoveredPoint.x / chartWidth) * 100}%`, 
                            top: `${(hoveredPoint.y / chartHeight) * 100}%` 
                        }"
                    >
                        <!-- Inner Dot -->
                        <div class="h-3 w-3 rounded-full bg-primary border-2 border-background"></div>
                    </div>
                </div>
            </div>

            <!-- X Axis Labels (4 evenly spaced days) -->
            <div class="flex justify-between text-[10px] font-bold text-muted-foreground uppercase tracking-wider pt-2 px-1 border-t border-border">
                <span>30 Days Ago</span>
                <span>20 Days Ago</span>
                <span>10 Days Ago</span>
                <span>Today</span>
            </div>
        </div>

        <!-- Recent Transactions Panel -->
        <div class="bg-card border border-border rounded-xl shadow-xl overflow-hidden">
            <div class="px-6 py-5 border-b border-border flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold text-foreground">Recent Transactions</h3>
                    <p class="text-xs text-muted-foreground">Latest payments flowing through the bridge</p>
                </div>
                <Link 
                    href="/transactions" 
                    class="inline-flex items-center text-xs font-bold text-primary hover:opacity-80 transition-opacity gap-1 uppercase tracking-wider group"
                >
                    View All 
                    <ArrowRight class="h-4 w-4 transform group-hover:translate-x-0.5 transition-transform" />
                </Link>
            </div>

            <div class="overflow-x-auto w-full">
                <table class="w-full text-left border-collapse min-w-[800px] whitespace-nowrap">
                    <thead>
                        <tr class="text-xs font-bold uppercase tracking-wider text-muted-foreground border-b border-border whitespace-nowrap">
                            <th class="px-6 py-4">Reference</th>
                            <th class="px-6 py-4">Merchant</th>
                            <th class="px-6 py-4">Gateway</th>
                            <th class="px-6 py-4">Method</th>
                            <th class="px-6 py-4">Amount</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr 
                            v-for="tx in recent_transactions" 
                            :key="tx.id"
                            class="hover:bg-muted/50 transition-colors group text-sm text-muted-foreground whitespace-nowrap"
                        >
                            <td class="px-6 py-4 font-mono font-semibold text-foreground">{{ tx.reference_id }}</td>
                            <td class="px-6 py-4 text-foreground font-medium">{{ tx.merchant_name }}</td>
                            <td class="px-6 py-4">
                                <span class="capitalize">{{ tx.gateway_name }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="bg-muted text-foreground px-2 py-0.5 rounded text-xs uppercase font-semibold border border-border">
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
                                    class="inline-flex items-center justify-center p-2 rounded-lg bg-muted text-foreground hover:bg-primary hover:text-black transition-all hover:scale-105"
                                >
                                    <ExternalLink class="h-4 w-4" />
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="recent_transactions.length === 0">
                            <td colspan="8" class="text-center py-12 text-muted-foreground italic text-sm">
                                No transactions found. Seed transactions or invoke payment creations via API to display data.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
