<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { ShieldAlert, ShieldCheck, Key, ArrowRightLeft, FileCode, CheckCircle2, Copy, Check } from '@lucide/vue';

const activeTab = ref<'schema' | 'verification' | 'examples'>('schema');
const copiedBlock = ref<string | null>(null);

function copySnippet(id: string, text: string) {
    navigator.clipboard.writeText(text);
    copiedBlock.value = id;
    setTimeout(() => {
        if (copiedBlock.value === id) {
            copiedBlock.value = null;
        }
    }, 2000);
}

const examplePayload = JSON.stringify(
    {
        reference_id: '8a987e4e-3cd1-4777-b9ba-66537bef28cb',
        merchant_ref_id: 'INV-1002',
        payment_gateway: 'pakasir',
        payment_method: 'qris',
        amount: 10070.0,
        fee: 70.0,
        total_amount: 10140.0,
        status: 'PAID',
        pg_status: 'completed',
        pg_ref_id: 'TRX18472948291',
        checkout_url: 'https://bridge.example.com/payments/checkout/8a987e4e-3cd1-4777-b9ba-66537bef28cb',
        qris_url: 'https://bridge.example.com/payments/qris/8a987e4e-3cd1-4777-b9ba-66537bef28cb.png',
        created_at: '2026-11-18T16:40:11+07:00',
        paid_at: '2026-11-18T16:40:30+07:00',
    },
    null,
    2,
);

const phpVerification = `<?php

// 1. Read the raw JSON request body and signature header
$payload = file_get_contents('php://input');
$receivedSignature = $_SERVER['HTTP_X_BRIDGE_SIGNATURE'] ?? '';

// 2. Your Merchant Webhook Secret (whsec_..., or legacy Merchant API Key)
$webhookSecret = getenv('BRIDGE_WEBHOOK_SECRET');

// 3. Compute local HMAC-SHA256 signature
$calculatedSignature = hash_hmac('sha256', $payload, $webhookSecret);

// 4. Validate signatures in constant time
if (hash_equals($calculatedSignature, $receivedSignature)) {
    $data = json_decode($payload, true);

    if (($data['status'] ?? null) === 'PAID') {
        // Update your order state idempotently
    }

    // 5. ALWAYS return HTTP 200 with JSON {"success": true}
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}

http_response_code(401);
header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid signature']);`;

const nodeVerification = `const crypto = require('crypto');
const express = require('express');
const app = express();

app.use(express.raw({ type: 'application/json' }));

app.post('/webhook', (req, res) => {
    const payload = req.body.toString('utf8');
    const receivedSignature = String(req.headers['x-bridge-signature'] || '');
    const webhookSecret = process.env.BRIDGE_WEBHOOK_SECRET || '';

    const calculatedSignature = crypto
        .createHmac('sha256', webhookSecret)
        .update(payload)
        .digest('hex');

    const receivedBuf = Buffer.from(receivedSignature, 'utf8');
    const expectedBuf = Buffer.from(calculatedSignature, 'utf8');

    // Guard buffer length before timingSafeEqual to avoid RangeError
    if (receivedBuf.length === expectedBuf.length && crypto.timingSafeEqual(receivedBuf, expectedBuf)) {
        const data = JSON.parse(payload);

        if (data.status === 'PAID') {
            // Update order status locally (idempotently)
        }

        return res.status(200).json({ success: true });
    }

    return res.status(401).json({ error: 'Invalid signature' });
});`;

const pythonVerification = `import os
import hmac
import hashlib
import json
from flask import Flask, request, jsonify

app = Flask(__name__)

WEBHOOK_SECRET = os.environ.get("BRIDGE_WEBHOOK_SECRET", "").encode("utf-8")

@app.route("/webhook", methods=["POST"])
def webhook():
    raw_payload = request.data
    received_signature = request.headers.get("X-Bridge-Signature", "")

    calculated_signature = hmac.new(
        WEBHOOK_SECRET,
        raw_payload,
        hashlib.sha256,
    ).hexdigest()

    if hmac.compare_digest(calculated_signature, received_signature):
        data = json.loads(raw_payload)
        if data.get("status") == "PAID":
            # Update order locally (idempotently)
            pass
        return jsonify({"success": True}), 200

    return jsonify({"error": "Invalid signature"}), 401`;
</script>

<template>
    <Head title="Merchant Webhook Documentation" />

    <AdminLayout title="Webhook Integration Manual">
        <div class="space-y-6">
            <!-- Documentation Header -->
            <div class="flex items-center justify-between border-b border-border pb-4 flex-wrap gap-4">
                <div>
                    <p class="text-xs text-muted-foreground mt-1">
                        Configure your merchant endpoint to listen, authenticate, and acknowledge callback events from Payment Bridge.
                    </p>
                </div>

                <div class="flex items-center gap-1 bg-card p-1 rounded-lg border border-border flex-wrap">
                    <button
                        @click="activeTab = 'schema'"
                        :class="[
                            'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all cursor-pointer',
                            activeTab === 'schema' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        Payload Schema
                    </button>
                    <button
                        @click="activeTab = 'verification'"
                        :class="[
                            'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all cursor-pointer',
                            activeTab === 'verification' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        Signature Verification
                    </button>
                    <button
                        @click="activeTab = 'examples'"
                        :class="[
                            'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all cursor-pointer',
                            activeTab === 'examples' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground',
                        ]"
                    >
                        Code Examples
                    </button>
                </div>
            </div>

            <!-- Tab 1: Payload Schema -->
            <div v-if="activeTab === 'schema'" class="grid gap-6 md:grid-cols-5">
                <!-- Info cards -->
                <Card class="bg-card border-border shadow-lg md:col-span-3 space-y-4 min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">Webhook HTTP Request Details</CardTitle>
                    </CardHeader>
                    <CardContent class="p-5 space-y-5">
                        <div class="space-y-2">
                            <div class="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase">
                                <ArrowRightLeft class="h-4 w-4 text-primary" />
                                <span>Request Method & Content Type</span>
                            </div>
                            <div class="flex gap-2 items-center font-mono text-xs">
                                <span class="bg-primary/20 text-primary border border-primary/20 px-2 py-0.5 rounded font-bold">POST</span>
                                <span class="bg-muted px-2 py-0.5 rounded border border-border text-foreground">application/json</span>
                            </div>
                        </div>

                        <div class="border-t border-border pt-4 space-y-3">
                            <div class="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase">
                                <Key class="h-4 w-4 text-primary" />
                                <span>Authentication Headers</span>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Every webhook request contains an
                                <code class="bg-muted px-1.5 py-0.5 rounded text-primary font-bold">X-Bridge-Signature</code>
                                header. It is generated by signing the raw JSON payload with your encrypted
                                <strong class="text-foreground">Merchant Webhook Secret</strong>
                                (<code class="bg-muted px-1 py-0.5 rounded text-foreground">whsec_...</code>, falling back to legacy
                                <code class="bg-muted px-1 py-0.5 rounded text-foreground">api_key</code> for older merchants) using the
                                <strong class="text-foreground">HMAC-SHA256</strong> hash algorithm. Always verify this signature before updating order state.
                            </p>
                        </div>

                        <div class="border-t border-border pt-4 space-y-3">
                            <div class="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase">
                                <CheckCircle2 class="h-4 w-4 text-primary" />
                                <span>Expected Merchant Response (CRITICAL)</span>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                To mark the transaction status successfully as <span class="text-emerald-500 font-bold">DONE</span>, your webhook listener must return a
                                <strong class="text-foreground">200 OK</strong> HTTP response containing:
                            </p>
                            <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-xs font-mono text-primary font-bold">{ "success": true }</pre>
                            <p class="text-xs text-yellow-500 flex items-start gap-1.5 bg-yellow-500/10 p-3 rounded-lg border border-yellow-500/20">
                                <ShieldAlert class="h-4 w-4 flex-shrink-0 mt-0.5" />
                                <span>
                                    <strong>Retry Policy:</strong> If your server returns a non-2xx status or times out (10s timeout / 3s connect timeout), the transaction remains
                                    <strong>PAID</strong> and the queue worker automatically retries up to <strong>5 times</strong> with exponential backoff
                                    (<code class="font-mono">10s → 60s → 300s → 1800s → 3600s</code>).
                                </span>
                            </p>
                        </div>

                        <div class="border-t border-border pt-4 space-y-2">
                            <div class="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase">
                                <ShieldCheck class="h-4 w-4 text-primary" />
                                <span>Endpoint Security (SSRF Protection)</span>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                Target webhook URLs must resolve to publicly routable IP addresses (loopback, private RFC 1918, link-local, and
                                <code class="bg-muted px-1 py-0.5 rounded text-foreground">.local</code>/<code class="bg-muted px-1 py-0.5 rounded text-foreground">.internal</code>
                                hosts are rejected). Production environments strictly require <strong class="text-foreground">HTTPS</strong>.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Payload JSON Sidebar -->
                <Card class="bg-card border-border shadow-lg md:col-span-2 min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4 flex flex-row items-center justify-between">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">JSON Payload Structure</CardTitle>
                        <button
                            @click="copySnippet('payload', examplePayload)"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded text-[11px] font-bold bg-muted text-muted-foreground hover:text-primary border border-border transition-colors cursor-pointer"
                            title="Copy JSON Payload"
                        >
                            <Check v-if="copiedBlock === 'payload'" class="h-3.5 w-3.5 text-primary" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            <span>{{ copiedBlock === 'payload' ? 'Copied' : 'Copy' }}</span>
                        </button>
                    </CardHeader>
                    <CardContent class="p-5">
                        <pre class="bg-muted p-4 rounded-lg border border-border text-[10px] font-mono text-foreground overflow-x-auto">{{ examplePayload }}</pre>
                    </CardContent>
                </Card>
            </div>

            <!-- Tab 2: Signature Verification -->
            <div v-if="activeTab === 'verification'">
                <Card class="bg-card border-border shadow-lg min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">How to Validate HMAC Signature</CardTitle>
                    </CardHeader>
                    <CardContent class="p-5 space-y-4">
                        <h3 class="text-xs font-bold uppercase text-primary">Calculation Formula</h3>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            To verify that an incoming callback genuinely originated from Payment Bridge:
                        </p>
                        <div class="bg-muted p-4 rounded-lg border border-border font-mono text-xs text-foreground">
                            Signature = HMAC-SHA256(Raw_Request_Body, Merchant_Webhook_Secret)
                        </div>
                        <ul class="text-xs text-muted-foreground list-disc list-inside space-y-2 leading-relaxed">
                            <li>
                                <strong class="text-foreground">Raw_Request_Body:</strong> The exact raw JSON string received in the HTTP body (do not re-encode parsed JSON objects).
                            </li>
                            <li>
                                <strong class="text-foreground">Merchant_Webhook_Secret:</strong> Your merchant's dedicated encrypted
                                <code class="bg-muted px-1 py-0.5 rounded text-foreground">webhook_secret</code>
                                (<code class="bg-muted px-1 py-0.5 rounded text-foreground">whsec_...</code>) issued upon merchant creation. Existing legacy merchants without a rotated secret continue to sign with their
                                <code class="bg-muted px-1 py-0.5 rounded text-foreground">api_key</code>.
                            </li>
                            <li>
                                <strong class="text-foreground">Constant-Time Comparison:</strong> Always compare the computed hex digest with
                                <code class="bg-muted px-1 py-0.5 rounded text-primary">X-Bridge-Signature</code> using constant-time comparison
                                (<code class="bg-muted px-1 py-0.5 rounded text-foreground">hash_equals</code>, <code class="bg-muted px-1 py-0.5 rounded text-foreground">crypto.timingSafeEqual</code>, or
                                <code class="bg-muted px-1 py-0.5 rounded text-foreground">hmac.compare_digest</code>).
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <!-- Tab 3: Code Examples -->
            <div v-if="activeTab === 'examples'" class="space-y-6">
                <!-- PHP example -->
                <Card class="bg-card border-border shadow-lg min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4 flex items-center justify-between flex-row">
                        <CardTitle class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                            <FileCode class="h-4 w-4 text-primary" />
                            PHP (Laravel / Native) Implementation
                        </CardTitle>
                        <button
                            @click="copySnippet('php', phpVerification)"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-[11px] font-bold bg-muted text-muted-foreground hover:text-primary border border-border transition-colors cursor-pointer"
                        >
                            <Check v-if="copiedBlock === 'php'" class="h-3.5 w-3.5 text-primary" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            <span>{{ copiedBlock === 'php' ? 'Copied' : 'Copy' }}</span>
                        </button>
                    </CardHeader>
                    <CardContent class="p-5">
                        <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-[11px] font-mono text-foreground leading-relaxed">{{ phpVerification }}</pre>
                    </CardContent>
                </Card>

                <!-- Node.js example -->
                <Card class="bg-card border-border shadow-lg min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4 flex items-center justify-between flex-row">
                        <CardTitle class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                            <FileCode class="h-4 w-4 text-primary" />
                            Node.js (Express) Implementation
                        </CardTitle>
                        <button
                            @click="copySnippet('node', nodeVerification)"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-[11px] font-bold bg-muted text-muted-foreground hover:text-primary border border-border transition-colors cursor-pointer"
                        >
                            <Check v-if="copiedBlock === 'node'" class="h-3.5 w-3.5 text-primary" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            <span>{{ copiedBlock === 'node' ? 'Copied' : 'Copy' }}</span>
                        </button>
                    </CardHeader>
                    <CardContent class="p-5">
                        <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-[11px] font-mono text-foreground leading-relaxed">{{ nodeVerification }}</pre>
                    </CardContent>
                </Card>

                <!-- Python example -->
                <Card class="bg-card border-border shadow-lg min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4 flex items-center justify-between flex-row">
                        <CardTitle class="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                            <FileCode class="h-4 w-4 text-primary" />
                            Python (Flask) Implementation
                        </CardTitle>
                        <button
                            @click="copySnippet('python', pythonVerification)"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded text-[11px] font-bold bg-muted text-muted-foreground hover:text-primary border border-border transition-colors cursor-pointer"
                        >
                            <Check v-if="copiedBlock === 'python'" class="h-3.5 w-3.5 text-primary" />
                            <Copy v-else class="h-3.5 w-3.5" />
                            <span>{{ copiedBlock === 'python' ? 'Copied' : 'Copy' }}</span>
                        </button>
                    </CardHeader>
                    <CardContent class="p-5">
                        <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-[11px] font-mono text-foreground leading-relaxed">{{ pythonVerification }}</pre>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
