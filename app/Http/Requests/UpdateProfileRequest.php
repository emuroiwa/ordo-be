<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
     */
    public function rules(): array
    {
        $userId = auth()->id();

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users')->ignore($userId)
            ],
            'phone' => 'nullable|string|max:20|regex:/^[\+]?[0-9\s\-\(\)]+$/',
            'business_name' => 'nullable|string|max:255',
            'service_category' => 'nullable|string|max:100',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|in:customer,vendor',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120', // 5MB max
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Name is required.',
            'name.max' => 'Name cannot exceed 255 characters.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Please enter a valid email address.',
            'email.unique' => 'This email address is already taken.',
            'phone.regex' => 'Please enter a valid phone number.',
            'phone.max' => 'Phone number cannot exceed 20 characters.',
            'business_name.max' => 'Business name cannot exceed 255 characters.',
            'service_category.max' => 'Service category cannot exceed 100 characters.',
            'roles.array' => 'Roles must be an array.',
            'roles.*.in' => 'Role must be either customer or vendor.',
            'avatar.image' => 'Avatar must be an image.',
            'avatar.mimes' => 'Avatar must be a JPEG, PNG, JPG, or WebP file.',
            'avatar.max' => 'Avatar file size cannot exceed 5MB.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure roles is always an array
        if ($this->has('roles') && !is_array($this->roles)) {
            $this->merge([
                'roles' => [$this->roles]
            ]);
        }

        // Trim whitespace from string fields
        $stringFields = ['name', 'business_name', 'service_category', 'phone'];
        $data = [];
        
        foreach ($stringFields as $field) {
            if ($this->has($field) && is_string($this->$field)) {
                $data[$field] = trim($this->$field);
            }
        }
        
        if (!empty($data)) {
            $this->merge($data);
        }
    }
}