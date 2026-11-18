<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference_id
 * @property int $merchant_id
 * @property string $merchant_ref_id
 * @property string|null $idempotency_key
 * @property int $payment_method_id
 * @property string|float $amount
 * @property string|float $fee
 * @property string|float $total_amount
 * @property string|null $pg_ref_id
 * @property array<string, mixed>|null $pg_response
 * @property string|null $checkout_url
 * @property string|null $qris_url
 * @property string|null $payment_code
 * @property string|null $redirect_url
 * @property string $status
 * @property string|null $pg_status
 * @property Carbon|null $paid_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentMethod $paymentMethod
 * @property-read Merchant $merchant
 */
#[Guarded(['id'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'pg_response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return HasMany<WebhookLog, $this>
     */
    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }
}
