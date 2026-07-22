<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    public function index(): Response
    {
        $apiKeys = ApiKey::with('merchant')->latest()->get()->map(function ($key) {
            return [
                'id' => $key->id,
                'name' => $key->name,
                'key' => $key->key,
                'is_active' => $key->is_active,
                'merchant_name' => $key->merchant->name ?? 'Global (All Merchants)',
                'last_used_at' => $key->last_used_at ? $key->last_used_at->toIso8601String() : null,
                'created_at' => $key->created_at->toIso8601String(),
            ];
        });

        $merchants = Merchant::select('id', 'name')->where('is_active', true)->get();

        return Inertia::render('ApiKeys/Index', [
            'api_keys' => $apiKeys,
            'merchants' => $merchants,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
        ]);

        // Global keys start with pb_live_, merchant keys with pb_mcht_
        $prefix = empty($validated['merchant_id']) ? 'pb_live_' : 'pb_mcht_';
        $key = $prefix.Str::random(40);

        ApiKey::create([
            'name' => $validated['name'],
            'merchant_id' => $validated['merchant_id'],
            'key' => $key,
            'is_active' => true,
        ]);

        return Inertia::flash('success', 'API Key generated successfully.')->back();
    }

    public function update(Request $request, ApiKey $apiKey): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $apiKey->update($validated);

        return Inertia::flash('success', "API Key '{$apiKey->name}' updated successfully.")->back();
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        return Inertia::flash('success', 'API Key revoked successfully.')->back();
    }
}
