<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBookingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Allow both authenticated and guest users
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isGuest = !auth()->check();
        
        return [
            'service_id' => ['required', 'uuid', 'exists:services,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'], // 15 min to 8 hours
            'customer_notes' => ['nullable', 'string', 'max:1000'],
            'location_type' => ['required', Rule::in(['vendor_location', 'customer_location', 'online'])],
            'service_address' => ['nullable', 'array'],
            'service_address.street' => ['required_if:location_type,customer_location', 'string', 'max:255'],
            'service_address.city' => ['required_if:location_type,customer_location', 'string', 'max:100'],
            'service_address.state' => ['required_if:location_type,customer_location', 'string', 'max:100'],
            'service_address.postal_code' => ['required_if:location_type,customer_location', 'string', 'max:20'],
            'service_address.country' => ['required_if:location_type,customer_location', 'string', 'max:100'],
            'service_address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'service_address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            
            // Guest booking fields - required only for unauthenticated users
            'guest_email' => $isGuest ? ['required', 'email', 'max:255'] : ['nullable', 'email', 'max:255'],
            'guest_phone' => $isGuest ? ['required', 'string', 'max:20'] : ['nullable', 'string', 'max:20'],
            'guest_name' => $isGuest ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'service_id.required' => 'Please select a service.',
            'service_id.exists' => 'The selected service is not available.',
            'scheduled_at.required' => 'Please select a booking date and time.',
            'scheduled_at.after' => 'Booking time must be in the future.',
            'duration_minutes.required' => 'Please specify the booking duration.',
            'duration_minutes.min' => 'Minimum booking duration is 15 minutes.',
            'duration_minutes.max' => 'Maximum booking duration is 8 hours.',
            'location_type.required' => 'Please specify the service location.',
            'service_address.street.required_if' => 'Street address is required for customer location services.',
            'service_address.city.required_if' => 'City is required for customer location services.',
            'guest_email.required' => 'Email address is required.',
            'guest_email.email' => 'Please enter a valid email address.',
            'guest_phone.required' => 'Phone number is required.',
            'guest_name.required' => 'Full name is required.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Custom validation logic can be added here
            // For example, checking service availability at the requested time
            
            if ($this->has('service_id') && $this->has('scheduled_at')) {
                $service = \App\Models\Service::find($this->service_id);
                
                if ($service && $service->user_id === auth()->id()) {
                    // $validator->errors()->add('service_id', 'You cannot book your own service.');
                }
            }
        });
    }
}
