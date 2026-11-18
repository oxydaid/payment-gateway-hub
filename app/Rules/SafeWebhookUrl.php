<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Security\WebhookUrlValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SafeWebhookUrl implements ValidationRule
{
    public function __construct(
        private readonly ?WebhookUrlValidator $validator = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $validator = $this->validator ?? app(WebhookUrlValidator::class);

        if (! $validator->isSafe($value)) {
            $fail('The :attribute must be a valid public URL and cannot target private or internal network addresses.');
        }
    }
}
