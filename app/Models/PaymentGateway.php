<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaymentGatewayFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class PaymentGateway extends Model
{
    /** @use HasFactory<PaymentGatewayFactory> */
    use HasFactory;

    /**
     * In-memory cache for resolved gateway icon URLs to avoid repeated filesystem lookups.
     *
     * @var array<string, ?string>
     */
    private static array $iconUrlCache = [];

    public function getIconUrlAttribute(): ?string
    {
        $code = (string) $this->code;
        if ($code === '') {
            return null;
        }

        if (array_key_exists($code, self::$iconUrlCache)) {
            return self::$iconUrlCache[$code];
        }

        foreach (['svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'] as $ext) {
            $file = public_path("images/payment-gateway/{$code}.{$ext}");
            if (file_exists($file)) {
                return self::$iconUrlCache[$code] = asset("images/payment-gateway/{$code}.{$ext}");
            }
        }

        return self::$iconUrlCache[$code] = null;
    }

    /**
     * Clear the in-memory icon URL lookup cache.
     */
    public static function clearIconUrlCache(): void
    {
        self::$iconUrlCache = [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * @param  Builder<PaymentGateway>  $query
     * @return Builder<PaymentGateway>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
