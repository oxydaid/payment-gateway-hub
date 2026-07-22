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
}
