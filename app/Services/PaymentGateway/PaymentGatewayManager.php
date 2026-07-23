<?php

namespace App\Services\PaymentGateway;

use App\Models\PaymentGateway;
use App\Services\PaymentGateway\Drivers\AriepulsaDriver;
use App\Services\PaymentGateway\Drivers\MidtransDriver;
use App\Services\PaymentGateway\Drivers\PakasirDriver;
use App\Services\PaymentGateway\Drivers\TokopayDriver;
use App\Services\PaymentGateway\Drivers\XenditDriver;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * Supported drivers mapping.
     *
     * @var array<string, class-string<GatewayDriverInterface>>
     */
    protected array $drivers = [
        'midtrans' => MidtransDriver::class,
        'tokopay' => TokopayDriver::class,
        'xendit' => XenditDriver::class,
        'pakasir' => PakasirDriver::class,
        'ariepulsa' => AriepulsaDriver::class,
    ];

    /**
     * Get array of supported driver codes.
     */
    public function getSupportedCodes(): array
    {
        return array_keys($this->drivers);
    }

    /**
     * Get validation rules for a given driver code.
     */
    public function getValidationRules(string $code): array
    {
        if (! isset($this->drivers[$code])) {
            throw new InvalidArgumentException("Unsupported gateway driver: {$code}");
        }

        $driverClass = $this->drivers[$code];

        if (method_exists($driverClass, 'getValidationRules')) {
            return $driverClass::getValidationRules();
        }

        return [];
    }

    /**
     * Instantiate a driver for a given PaymentGateway instance.
     */
    public function driver(PaymentGateway $gateway): GatewayDriverInterface
    {
        $code = $gateway->code;

        if (! isset($this->drivers[$code])) {
            throw new InvalidArgumentException("Unsupported gateway driver: {$code}");
        }

        $driverClass = $this->drivers[$code];

        return new $driverClass($gateway->credentials ?? []);
    }

    /**
     * Get metadata and credential schemas of all registered drivers.
     */
    public function getAvailableDrivers(): array
    {
        return [
            [
                'name' => 'Midtrans Payment Gateway',
                'code' => 'midtrans',
                'fields' => [
                    ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'placeholder' => 'G123456789'],
                    ['key' => 'client_key', 'label' => 'Client Key', 'type' => 'text', 'placeholder' => 'SB-Mid-client-...'],
                    ['key' => 'server_key', 'label' => 'Server Key', 'type' => 'password', 'placeholder' => 'SB-Mid-server-...'],
                ],
            ],
            [
                'name' => 'Tokopay Payment Gateway',
                'code' => 'tokopay',
                'fields' => [
                    ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'placeholder' => 'M123456'],
                    ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'placeholder' => 'Secret key...'],
                ],
            ],
            [
                'name' => 'Xendit Payment Gateway',
                'code' => 'xendit',
                'fields' => [
                    ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'placeholder' => 'xnd_development_...'],
                    ['key' => 'callback_token', 'label' => 'Callback Verification Token', 'type' => 'text', 'placeholder' => 'Callback token from settings...'],
                ],
            ],
            [
                'name' => 'Pakasir Payment Gateway',
                'code' => 'pakasir',
                'fields' => [
                    ['key' => 'project_slug', 'label' => 'Project Slug', 'type' => 'text', 'placeholder' => 'depodomain'],
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'Project API Key...'],
                ],
            ],
            [
                'name' => 'Ariepulsa Payment Gateway',
                'code' => 'ariepulsa',
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'placeholder' => 'API Key from Arie Pulsa Profile...'],
                    ['key' => 'type_fee', 'label' => 'Type Fee (1: Customer, 2: Merchant)', 'type' => 'text', 'placeholder' => '1'],
                ],
            ],
        ];
    }

    /**
     * Get list of payment methods supported by a given driver.
     */
    public function getPaymentMethods(string $code): array
    {
        if (! isset($this->drivers[$code])) {
            return [];
        }

        $driverClass = $this->drivers[$code];

        if (method_exists($driverClass, 'getPaymentMethods')) {
            return $driverClass::getPaymentMethods();
        }

        return [];
    }
}
