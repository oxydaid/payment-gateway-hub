<?php

namespace App\Http\Requests\Api\v1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $merchant = $this->attributes->get('merchant');
        $isGlobalKey = $this->attributes->get('is_global_key');

        return [
            'merchant_id' => [
                Rule::requiredIf(fn () => $isGlobalKey && ! $merchant),
                'integer',
                Rule::exists('merchants', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'merchant_ref_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('transactions')->where(function ($query) use ($merchant) {
                    $mId = $merchant?->id ?? $this->input('merchant_id');

                    return $query->where('merchant_id', $mId);
                }),
            ],
            'payment_method_id' => [
                'required',
                'integer',
                Rule::exists('payment_methods', 'id')->where(function ($query) {
                    return $query->where('is_active', true);
                }),
            ],
            'amount' => ['required', 'numeric', 'min:100'],
            'redirect_url' => ['required', 'url', 'max:500'],
        ];
    }
}
