<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
        // return auth()->check() && auth()->user()->isVendor();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
            'description' => 'required|string|max:5000',
            'short_description' => 'required|string|max:200',
            'category_id' => 'required|uuid|exists:service_categories,id',
            'price_type' => 'required|in:fixed,hourly,negotiable',
            'base_price' => 'required|numeric|min:0|max:999999.99',
            'max_price' => 'nullable|numeric|min:0|max:999999.99|gte:base_price',
            'currency' => 'required|string|size:3|in:ZAR,USD,EUR,GBP',
            'duration_minutes' => 'nullable|integer|min:15|max:1440',
            'location_type' => 'required|in:client_location,service_location,online',
            'address' => 'nullable|json',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'tags' => 'nullable|json',
            'requirements' => 'nullable|json',
            'instant_booking' => 'boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,webp|max:10240', // 10MB max
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Service title is required.',
            'title.max' => 'Service title cannot exceed 100 characters.',
            'description.required' => 'Service description is required.',
            'description.max' => 'Service description cannot exceed 5000 characters.',
            'short_description.required' => 'Short description is required.',
            'short_description.max' => 'Short description cannot exceed 200 characters.',
            'category_id.required' => 'Please select a service category.',
            'category_id.exists' => 'Selected category is not valid.',
            'price_type.required' => 'Please select a price type.',
            'price_type.in' => 'Price type must be fixed, hourly, or negotiable.',
            'base_price.required' => 'Base price is required.',
            'base_price.numeric' => 'Base price must be a valid number.',
            'base_price.min' => 'Base price cannot be negative.',
            'max_price.gte' => 'Maximum price must be greater than or equal to base price.',
            'currency.required' => 'Currency is required.',
            'currency.in' => 'Currency must be ZAR, USD, EUR, or GBP.',
            'location_type.required' => 'Please select where you provide this service.',
            'address.city.required_unless' => 'City is required for physical services.',
            'address.province.required_unless' => 'Province is required for physical services.',
            'images.max' => 'You can upload a maximum of 10 images.',
            'images.*.image' => 'All uploaded files must be images.',
            'images.*.mimes' => 'Images must be JPEG, PNG, or WebP format.',
            'images.*.max' => 'Each image must be less than 10MB.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Parse JSON fields if they come as strings
        if ($this->has('tags') && is_string($this->tags)) {
            $this->merge([
                'tags' => json_decode($this->tags, true) ?: []
            ]);
        }

        if ($this->has('requirements') && is_string($this->requirements)) {
            $this->merge([
                'requirements' => json_decode($this->requirements, true) ?: []
            ]);
        }

        // Convert instant_booking to boolean
        if ($this->has('instant_booking')) {
            $this->merge([
                'instant_booking' => filter_var($this->instant_booking, FILTER_VALIDATE_BOOLEAN)
            ]);
        }
    }
}