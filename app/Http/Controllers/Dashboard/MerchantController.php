<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Rules\SafeWebhookUrl;
use App\Services\Security\WebhookUrlValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MerchantController extends Controller
{
    public function __construct(
        protected WebhookUrlValidator $webhookUrlValidator,
    ) {}

    public function index(Request $request): Response
    {
        $query = Merchant::withCount('transactions')->with('apiKeys');

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('webhook_url', 'like', "%{$search}%");
            });
        }

        $merchants = $query->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Merchants/Index', [
            'merchants' => $merchants,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:255', new SafeWebhookUrl($this->webhookUrlValidator)],
            'is_active' => ['required', 'boolean'],
        ]);

        $generatedKey = 'pb_mcht_'.Str::random(40);
        $webhookSecret = 'whsec_'.Str::random(40);
        $validated['api_key'] = $generatedKey;
        $validated['webhook_secret'] = $webhookSecret;

        $merchant = Merchant::create($validated);

        // Also create a record in the api_keys table for unified management
        ApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => 'Default Key ('.$merchant->name.')',
            'key_prefix' => ApiKey::maskToken($generatedKey),
            'key_hash' => ApiKey::hashToken($generatedKey),
            'is_active' => true,
        ]);

        return Inertia::flash([
            'success' => "Merchant '{$merchant->name}' created successfully with auto-generated API Key.",
            'new_api_key' => $generatedKey,
            'new_webhook_secret' => $webhookSecret,
        ])->back();
    }

    public function update(Request $request, Merchant $merchant): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:255', new SafeWebhookUrl($this->webhookUrlValidator)],
            'is_active' => ['required', 'boolean'],
        ]);

        $merchant->update($validated);

        return Inertia::flash('success', "Merchant '{$merchant->name}' updated successfully.")->back();
    }

    public function destroy(Merchant $merchant): RedirectResponse
    {
        $name = $merchant->name;
        $merchant->delete();

        return Inertia::flash('success', "Merchant '{$name}' deleted successfully.")->back();
    }

    public function generateKey(Merchant $merchant): RedirectResponse
    {
        $newKey = 'pb_mcht_'.Str::random(40);

        $merchant->update(['api_key' => $newKey]);

        ApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => 'Regenerated Key ('.date('Y-m-d H:i').')',
            'key_prefix' => ApiKey::maskToken($newKey),
            'key_hash' => ApiKey::hashToken($newKey),
            'is_active' => true,
        ]);

        return Inertia::flash([
            'success' => "New API Key generated for merchant '{$merchant->name}'.",
            'new_api_key' => $newKey,
        ])->back();
    }

    public function testWebhook(Request $request, Merchant $merchant): JsonResponse
    {
        if (empty($merchant->webhook_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook URL is not configured.',
            ], 400);
        }

        if (! $this->webhookUrlValidator->isSafe($merchant->webhook_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook URL is blocked by SSRF protection (must target a valid public endpoint).',
            ], 400);
        }

        // Prepare dummy payload
        $payload = [
            'reference_id' => (string) Str::uuid(),
            'merchant_ref_id' => 'TEST-'.rand(1000, 9999),
            'payment_gateway' => 'midtrans',
            'payment_method' => 'qris',
            'amount' => 10000.00,
            'fee' => 0.00,
            'total_amount' => 10000.00,
            'status' => 'PAID',
            'pg_status' => 'settlement',
            'pg_ref_id' => 'TRX-TEST-'.strtoupper(Str::random(10)),
            'checkout_url' => url('/'),
            'qris_url' => null,
            'created_at' => now()->toIso8601String(),
            'paid_at' => now()->toIso8601String(),
        ];

        // Sign payload with merchant webhook signing secret (encrypted webhook_secret or legacy api_key)
        $encodedPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $encodedPayload !== false ? $encodedPayload : '', $merchant->signingSecret());

        try {
            $startTime = microtime(true);
            $response = Http::withHeaders([
                'X-Bridge-Signature' => $signature,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->timeout(10)
                ->connectTimeout(3)
                ->post($merchant->webhook_url, $payload);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $isSuccessResponse = false;
            if ($response->successful()) {
                $responseData = $response->json();
                if (is_array($responseData) && isset($responseData['success']) && ($responseData['success'] === true || $responseData['success'] === 'true')) {
                    $isSuccessResponse = true;
                }
            }

            return response()->json([
                'success' => $isSuccessResponse,
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $duration,
                'notes' => $isSuccessResponse
                    ? 'Webhook delivered successfully and accepted by merchant.'
                    : ($response->successful()
                        ? 'Connected, but merchant did not return {"success": true}.'
                        : 'Webhook endpoint returned error status code.'),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                'response_body' => null,
                'notes' => 'Connection failed: '.$e->getMessage(),
            ]);
        }
    }
}
