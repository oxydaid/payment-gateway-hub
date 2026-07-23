<?php

namespace App\Http\Resources\Api\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference_id' => $this->reference_id,
            'merchant_ref_id' => $this->merchant_ref_id,
            'payment_method' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),
            'amount' => (float) $this->amount,
            'fee' => (float) $this->fee,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'pg_status' => $this->pg_status,
            'checkout_url' => $this->checkout_url,
            'qris_url' => $this->qris_url,
            'payment_code' => $this->payment_code,
            'redirect_url' => $this->redirect_url,
            'pg_ref_id' => $this->pg_ref_id,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
