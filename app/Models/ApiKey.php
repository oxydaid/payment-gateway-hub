<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $merchant_id
 * @property string $name
 * @property string|null $key_prefix
 * @property string $key_hash
 * @property bool $is_active
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Merchant|null $merchant
 */
#[Guarded(['id'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    /**
     * Hash a plain-text API token for storage or lookup.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Generate a masked display prefix for a plain-text API token.
     */
    public static function maskToken(string $plainToken): string
    {
        return substr($plainToken, 0, 16).'****';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
