<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\PaymentMethod;
use App\Services\PaymentGateway\PaymentGatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentGatewayController extends Controller
{
    public function __construct(protected PaymentGatewayManager $gatewayManager) {}

    public function index(): Response
    {
        $gateways = PaymentGateway::with('paymentMethods')->get()->map(function ($gateway) {
            return [
                'id' => $gateway->id,
                'name' => $gateway->name,
                'code' => $gateway->code,
                'is_active' => $gateway->is_active,
                'credentials' => $gateway->credentials,
                'icon_url' => $gateway->icon_url,
                'payment_methods' => $gateway->paymentMethods->map(function ($method) {
                    return [
                        'id' => $method->id,
                        'name' => $method->name,
                        'code' => $method->code,
                        'is_active' => $method->is_active,
                        'fee_type' => $method->fee_type,
                        'fee_fix' => $method->fee_fix,
                        'fee_percent' => $method->fee_percent,
                        'icon_url' => $method->icon ? asset('storage/'.$method->icon) : null,
                    ];
                }),
            ];
        });

        return Inertia::render('PaymentGateways/Index', [
            'gateways' => $gateways,
            'available_drivers' => $this->gatewayManager->getAvailableDrivers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supportedDrivers = $this->gatewayManager->getSupportedCodes();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                'unique:payment_gateways,code',
                Rule::in($supportedDrivers),
            ],
            'is_active' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array'],
        ]);

        $gateway = PaymentGateway::create([
            'name' => $validated['name'],
            'code' => strtolower($validated['code']),
            'is_active' => $validated['is_active'],
            'credentials' => $validated['credentials'] ?? [],
        ]);

        return Inertia::flash('success', "Payment gateway '{$gateway->name}' created successfully.")->back();
    }

    public function update(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $validated = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ], $this->gatewayManager->getValidationRules($gateway->code)));

        $gateway->update([
            'name' => $validated['name'],
            'credentials' => $validated['credentials'] ?? [],
            'is_active' => $validated['is_active'],
        ]);

        return Inertia::flash('success', "Payment gateway '{$gateway->name}' configuration has been updated successfully.")->back();
    }

    public function destroy(PaymentGateway $gateway): RedirectResponse
    {
        $name = $gateway->name;
        $gateway->delete();

        return Inertia::flash('success', "Payment gateway '{$name}' deleted successfully.")->back();
    }

    // --- Payment Methods Channel CRUD ---

    public function storeMethod(Request $request, PaymentGateway $gateway): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'type' => ['nullable', 'in:va,qris,ewallet,retail,credit_card'],
            'fee_type' => ['required', 'in:fix,percent,mix'],
            'fee_fix' => ['required', 'numeric', 'min:0'],
            'fee_percent' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'icon' => ['nullable', 'mimes:png,jpg,jpeg,gif,webp,svg,bmp,avif', 'max:2048'],
        ]);

        $validated['type'] = $validated['type'] ?? 'qris';

        $gateway->paymentMethods()->create(array_merge($validated, [
            'icon' => $request->file('icon'),
        ]));

        return Inertia::flash('success', "Payment method '{$validated['name']}' added to {$gateway->name}.")->back();
    }

    public function updateMethod(Request $request, PaymentMethod $method): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fee_type' => ['required', 'in:fix,percent,mix'],
            'fee_fix' => ['required', 'numeric', 'min:0'],
            'fee_percent' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'icon' => ['nullable', 'mimes:png,jpg,jpeg,gif,webp,svg,bmp,avif', 'max:2048'],
        ]);

        $updateData = [
            'name' => $validated['name'],
            'fee_type' => $validated['fee_type'],
            'fee_fix' => $validated['fee_fix'],
            'fee_percent' => $validated['fee_percent'],
            'is_active' => $validated['is_active'],
        ];

        if ($request->hasFile('icon')) {
            $updateData['icon'] = $request->file('icon');
        }

        $method->update($updateData);

        return Inertia::flash('success', "Payment method '{$method->name}' updated successfully.")->back();
    }

    public function destroyMethod(PaymentMethod $method): RedirectResponse
    {
        $name = $method->name;
        $method->delete();

        return Inertia::flash('success', "Payment method '{$name}' removed successfully.")->back();
    }
}
