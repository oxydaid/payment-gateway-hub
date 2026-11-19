<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class AbstractGatewayDriver implements GatewayDriverInterface
{
    /**
     * Throw an exception if the HTTP response failed, with structured error logging.
     */
    protected function throwIfFailed(Response $response, string $context, int|string $transactionId): void
    {
        if ($response->failed()) {
            Log::error("{$context} failed", [
                'transaction_id' => $transactionId,
                'http_status' => $response->status(),
                'response' => $response->json() ?? $response->body(),
            ]);

            $response->throw();
        }
    }

    /**
     * Resolve paid_at timestamp from an upstream date string, falling back to now().
     */
    protected function resolvePaidAt(?string $rawDate): CarbonImmutable
    {
        if ($rawDate !== null && trim($rawDate) !== '') {
            try {
                return CarbonImmutable::parse($rawDate);
            } catch (Throwable) {
                // fall through to now()
            }
        }

        return now()->toImmutable();
    }

    /**
     * Whether this driver requires a fresh status poll from the gateway
     * to complete the webhook handling. Drivers that do not include status
     * in their callback payload should override this.
     */
    public function requiresStatusPollForWebhook(): bool
    {
        return false;
    }
}
