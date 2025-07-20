<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_notes' => ['nullable', 'string', 'max:1000'],
            'vendor_notes' => ['nullable', 'string', 'max:1000'],
            'service_address' => ['nullable', 'array'],
            'service_address.street' => ['string', 'max:255'],
            'service_address.city' => ['string', 'max:100'],
            'service_address.state' => ['string', 'max:100'],
            'service_address.postal_code' => ['string', 'max:20'],
            'service_address.country' => ['string', 'max:100'],
            'service_address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'service_address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'customer_notes.max' => 'Customer notes cannot exceed 1000 characters.',
            'vendor_notes.max' => 'Vendor notes cannot exceed 1000 characters.',
        ];
    }
}
