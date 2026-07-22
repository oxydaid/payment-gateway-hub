<?php

namespace App\Http\Resources\Api\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'fee_type' => $this->fee_type,
            'fee_fix' => (float) $this->fee_fix,
            'fee_percent' => (float) $this->fee_percent,
            'icon_url' => $this->icon ? asset('storage/'.$this->icon) : null,
            'gateway_code' => $this->gateway?->code,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
