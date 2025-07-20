<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'business_name' => $this->business_name,
            'slug' => $this->slug,
            'service_category' => $this->service_category,
            'roles' => $this->roles ?? ['customer'],
            'is_vendor' => $this->isVendor(),
            'is_customer' => $this->isCustomer(),
            'is_active' => $this->is_active,
            'avatar' => $this->avatar,
            'avatar_url' => $this->avatar_url,
            'email_verified_at' => $this->email_verified_at,
            'phone_verified_at' => $this->phone_verified_at,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Conditional data
            'unread_notifications_count' => $this->when(
                $request->user()?->id === $this->id,
                fn () => $this->unreadNotificationsCount()
            ),
            
            // Profile statistics (only for own profile)
            'stats' => $this->when(
                $request->user()?->id === $this->id,
                fn () => [
                    'services_count' => $this->isVendor() ? $this->services()->count() : 0,
                    'active_services_count' => $this->isVendor() ? $this->services()->where('status', 'active')->count() : 0,
                ]
            ),
        ];
    }
}