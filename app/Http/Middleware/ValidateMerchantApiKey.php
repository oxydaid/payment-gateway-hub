<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateMerchantApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'API Key is missing.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $hashedToken = ApiKey::hashToken($token);

        $apiKey = ApiKey::where('key_hash', $hashedToken)
            ->where('is_active', true)
            ->first();

        if ($apiKey !== null && hash_equals($apiKey->key_hash, $hashedToken)) {
            $apiKey->update(['last_used_at' => now()]);

            if ($apiKey->merchant_id) {
                // Merchant-specific API Key
                $merchant = Merchant::where('id', $apiKey->merchant_id)
                    ->where('is_active', true)
                    ->first();

                if (! $merchant) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid or inactive Merchant context for this API Key.',
                    ], Response::HTTP_UNAUTHORIZED);
                }
            } else {
                // Global API Key - optionally resolve merchant from request payload
                $merchantId = $request->input('merchant_id') ?? $request->header('X-Merchant-ID');

                if ($merchantId) {
                    $merchant = Merchant::where('id', $merchantId)
                        ->where('is_active', true)
                        ->first();

                    if (! $merchant) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Merchant context not found or inactive.',
                        ], Response::HTTP_NOT_FOUND);
                    }
                } else {
                    $merchant = null;
                }
            }
        } else {
            // Fallback to legacy merchant api_key for backward compatibility
            $merchant = Merchant::where('api_key', $token)
                ->where('is_active', true)
                ->first();

            if (! $merchant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive API Key.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        }

        // Store merchant and key metadata in request attributes
        $request->attributes->set('merchant', $merchant);
        $request->attributes->set('is_global_key', $apiKey !== null && ! $apiKey->merchant_id);

        return $next($request);
    }
}
