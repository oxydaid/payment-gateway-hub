<?php

declare(strict_types=1);

namespace App\Http\Resources\Dashboard;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_id' => $this->reference_id,
            'merchant_name' => $this->merchant->name,
            'gateway_name' => $this->paymentMethod->gateway->name ?? 'Unknown',
            'method_name' => $this->paymentMethod->name,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'total_amount' => $this->total_amount,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
