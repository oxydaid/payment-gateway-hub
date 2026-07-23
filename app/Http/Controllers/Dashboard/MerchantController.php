<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MerchantController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Merchant::withCount('transactions')->with('apiKeys');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('webhook_url', 'like', "%{$search}%");
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
            'webhook_url' => ['nullable', 'url', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $generatedKey = 'pb_mcht_'.Str::random(40);
        $validated['api_key'] = $generatedKey;

        $merchant = Merchant::create($validated);

        // Also create a record in the api_keys table for unified management
        ApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => 'Default Key ('.$merchant->name.')',
            'key' => $generatedKey,
            'is_active' => true,
        ]);

        return Inertia::flash('success', "Merchant '{$merchant->name}' created successfully with auto-generated API Key.")->back();
    }

    public function update(Request $request, Merchant $merchant): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
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
            'key' => $newKey,
            'is_active' => true,
        ]);

        return Inertia::flash('success', "New API Key generated for merchant '{$merchant->name}'.")->back();
    }

    public function testWebhook(Request $request, Merchant $merchant): JsonResponse
    {
        if (empty($merchant->webhook_url)) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook URL is not configured.',
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

        // Sign payload with merchant api key
        $signature = hash_hmac('sha256', json_encode($payload), $merchant->api_key ?? '');

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
                if (isset($responseData['success']) && ($responseData['success'] === true || $responseData['success'] === 'true')) {
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
