<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                    'color' => $this->category->color,
                    'icon' => $this->category->icon,
                ];
            }),
            'price_type' => $this->price_type,
            'base_price' => $this->base_price,
            'max_price' => $this->max_price,
            'currency' => $this->currency,
            'duration_minutes' => $this->duration_minutes,
            'location_type' => $this->location_type,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'tags' => $this->tags ?? [],
            'requirements' => $this->requirements ?? [],
            'status' => $this->status,
            'is_featured' => $this->is_featured,
            'instant_booking' => $this->instant_booking,
            'slug' => $this->slug,
            'full_slug' => $this->full_slug,
            'average_rating' => $this->average_rating,
            'review_count' => $this->review_count,
            'view_count' => $this->view_count,
            'booking_count' => $this->booking_count,
            'formatted_price' => $this->formatted_price,
            'location_display' => $this->location_display,
            'primary_image' => $this->primary_image,
            'service_images' => ServiceImageResource::collection($this->whenLoaded('serviceImages')),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'business_name' => $this->user->business_name,
                    'slug' => $this->user->slug,
                    'avatar_url' => $this->user->avatar_url,
                    'created_at' => $this->user->created_at,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}