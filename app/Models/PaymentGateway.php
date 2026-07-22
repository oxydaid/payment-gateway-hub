<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class PaymentGateway extends Model
{
    use HasFactory;

    public function getIconUrlAttribute(): ?string
    {
        foreach (['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'] as $ext) {
            $file = public_path("images/payment-gateway/{$this->code}.{$ext}");
            if (file_exists($file)) {
                return asset("images/payment-gateway/{$this->code}.{$ext}");
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
