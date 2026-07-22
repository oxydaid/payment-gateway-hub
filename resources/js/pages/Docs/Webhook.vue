<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { ShieldAlert, Key, ArrowRightLeft, FileCode, CheckCircle2 } from '@lucide/vue';

const activeTab = ref<'schema' | 'verification' | 'examples'>('schema');

const examplePayload = JSON.stringify({
  "reference_id": "8a987e4e-3cd1-4777-b9ba-66537bef28cb",
  "merchant_ref_id": "INV-1002",
  "payment_gateway": "midtrans",
  "payment_method": "qris",
  "amount": 10070.00,
  "fee": 70.00,
  "total_amount": 10140.00,
  "status": "PAID",
  "pg_status": "settlement",
  "created_at": "2026-07-22T16:40:11+07:00",
  "paid_at": "2026-07-22T16:40:30+07:00"
}, null, 2);

const phpVerification = `<?php

// 1. Get the payload and header signature
$payload = file_get_contents('php://input');
$receivedSignature = $_SERVER['HTTP_X_BRIDGE_SIGNATURE'] ?? '';

// 2. Your merchant API Key (from Bridge Dashboard)
$apiKey = 'your_merchant_api_key_here';

// 3. Compute local HMAC SHA256 signature
$calculatedSignature = hash_hmac('sha256', $payload, $apiKey);

// 4. Validate signatures match
if (hash_equals($calculatedSignature, $receivedSignature)) {
    // Signature is valid. Process the payload.
    $data = json_decode($payload, true);
    
    if ($data['status'] === 'PAID') {
        // Update your order state to paid/processing
    }

    // 5. ALWAYS return JSON {"success": true}
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
} else {
    // Invalid signature
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit();
}`;

const nodeVerification = `const crypto = require('crypto');
const express = require('express');
const app = express();

app.use(express.raw({ type: 'application/json' }));

app.post('/webhook', (req, res) => {
    const payload = req.body.toString();
    const receivedSignature = req.headers['x-bridge-signature'];
    const apiKey = 'your_merchant_api_key_here';

    // Compute signature
    const calculatedSignature = crypto
        .createHmac('sha256', apiKey)
        .update(payload)
        .digest('hex');

    if (crypto.timingSafeEqual(Buffer.from(receivedSignature), Buffer.from(calculatedSignature))) {
        const data = JSON.parse(payload);
        
        if (data.status === 'PAID') {
            // Update order status locally
        }

        // Return expected success format
        return res.status(200).json({ success: true });
    } else {
        return res.status(401).json({ error: 'Invalid signature' });
    }
});`;

const pythonVerification = `import hmac
import hashlib
import json
from flask import Flask, request, jsonify

app = Flask(__name__)

API_KEY = b"your_merchant_api_key_here"

@app.route('/webhook', methods=['POST'])
def webhook():
    raw_payload = request.data
    received_signature = request.headers.get('X-Bridge-Signature', '')
    
    # Compute signature
    calculated_signature = hmac.new(
        API_KEY, 
        raw_payload, 
        hashlib.sha256
    ).hexdigest()
    
    if hmac.compare_digest(calculated_signature, received_signature):
        data = json.loads(raw_payload)
        if data.get('status') == 'PAID':
            # Update order locally
            pass
        return jsonify({"success": True}), 200
    else:
        return jsonify({"error": "Invalid signature"}), 401`;
</script>

<template>
    <Head title="Merchant Webhook Documentation" />

    <AdminLayout title="Webhook Integration Manual">
        <div class="space-y-6">
            <!-- Documentation Header -->
            <div class="flex items-center justify-between border-b border-border pb-4 flex-wrap gap-4">
                <div>
                    <p class="text-xs text-muted-foreground mt-1">Configure your merchant endpoint to listen, authenticate, and acknowledge callback events from Payment Bridge.</p>
                </div>

                <div class="flex items-center gap-1 bg-card p-1 rounded-lg border border-border flex-wrap">
                    <button 
                        @click="activeTab = 'schema'"
                        :class="[
                            'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all',
                            activeTab === 'schema' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground'
                        ]"
                    >
                        Payload Schema
                    </button>
                    <button 
                        @click="activeTab = 'verification'"
                        :class="[
                            'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all',
                            activeTab === 'verification' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground'
                        ]"
                    >
                        Signature Verification
                    </button>
                    <button 
                        @click="activeTab = 'examples'"
                        :class="[
                            'px-4 py-1.5 rounded-md text-xs font-bold uppercase transition-all',
                            activeTab === 'examples' ? 'bg-muted text-primary' : 'text-muted-foreground hover:text-foreground'
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
                                Every webhook request contains an <code class="bg-muted px-1.5 py-0.5 rounded text-primary font-bold">X-Bridge-Signature</code> header.
                                It is generated by signing the raw JSON payload with your Merchant API Key using the **HMAC SHA256** hash algorithm. 
                                Always verify this signature to ensure payloads are authentic and untampered with.
                            </p>
                        </div>

                        <div class="border-t border-border pt-4 space-y-3">
                            <div class="flex items-center gap-2 text-xs font-bold text-muted-foreground uppercase">
                                <CheckCircle2 class="h-4 w-4 text-primary" />
                                <span>Expected Merchant Response (CRITICAL)</span>
                            </div>
                            <p class="text-xs text-muted-foreground leading-relaxed">
                                To mark the transaction status successfully as <span class="text-emerald-500 font-bold">DONE</span>, your webhook listener must return a 
                                <strong class="text-foreground">200 OK</strong> HTTP response containing exactly:
                            </p>
                            <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-xs font-mono text-primary font-bold">{ "success": true }</pre>
                            <p class="text-xs text-yellow-500 flex items-start gap-1.5 bg-yellow-500/10 p-3 rounded-lg border border-yellow-500/20">
                                <ShieldAlert class="h-4 w-4 flex-shrink-0 mt-0.5" />
                                <span><strong>Warning:</strong> If your server returns any other response body, or returns an error status (like 500/404), the webhook transaction remains <strong>PAID</strong> but does not transition to <strong>DONE</strong>. The bridge will keep retrying to send the webhook event up to 3 times in the background.</span>
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Payload JSON Sidebar -->
                <Card class="bg-card border-border shadow-lg md:col-span-2 min-w-0">
                    <CardHeader class="border-b border-border px-5 py-4">
                        <CardTitle class="text-sm font-bold uppercase tracking-wider text-foreground">JSON Payload Structure</CardTitle>
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
                            To manually compute the signature of a webhook request:
                        </p>
                        <div class="bg-muted p-4 rounded-lg border border-border font-mono text-xs text-foreground">
                            Signature = HMAC-SHA256(Raw_Request_Body, Merchant_Api_Key)
                        </div>
                        <ul class="text-xs text-muted-foreground list-disc list-inside space-y-2 leading-relaxed">
                            <li><strong class="text-foreground">Raw_Request_Body:</strong> The absolute raw JSON input string (exact characters, whitespace, and formatting). Do not use parsed parameters or arrays.</li>
                            <li><strong class="text-foreground">Merchant_Api_Key:</strong> The secure token generated for your merchant client profile inside the dashboard. Keep this secret.</li>
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
                            PHP (Laravel/Native) Implementation
                        </CardTitle>
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
                    </CardHeader>
                    <CardContent class="p-5">
                        <pre class="bg-muted p-4 rounded-lg border border-border overflow-x-auto text-[11px] font-mono text-foreground leading-relaxed">{{ pythonVerification }}</pre>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
