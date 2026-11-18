<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\FileUploadHandler;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payment_gateway_id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property string $fee_type
 * @property string|float $fee_fix
 * @property string|float $fee_percent
 * @property string|null $icon
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentGateway|null $gateway
 */
#[Guarded(['id'])]
class PaymentMethod extends Model
{
    use FileUploadHandler;

    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    /** @var array<int, string> */
    public array $fileFields = ['icon'];

    /** @var array<string, string> */
    public array $fileDirectories = [
        'icon' => 'payment-methods',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PaymentGateway, $this>
     */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
