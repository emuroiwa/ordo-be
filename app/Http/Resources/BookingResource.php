<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_reference' => $this->booking_reference,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'end_time' => $this->end_time,
            'duration_minutes' => $this->duration_minutes,
            'total_amount' => $this->total_amount,
            'deposit_amount' => $this->deposit_amount,
            'currency' => $this->currency,
            'formatted_price' => $this->formatted_price,
            'customer_notes' => $this->customer_notes,
            'vendor_notes' => $this->when($this->isVendorOrCustomer($request), $this->vendor_notes),
            'location_type' => $this->location_type,
            'service_address' => $this->service_address,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Computed attributes
            'is_upcoming' => $this->is_upcoming,
            'can_be_cancelled' => $this->can_be_cancelled,
            'can_be_rescheduled' => $this->can_be_rescheduled,
            'deposit_percentage' => $this->deposit_percentage,

            // Payment information
            'total_paid_amount' => $this->getTotalPaidAmount(),
            'remaining_amount' => $this->getRemainingAmount(),
            'is_fully_paid' => $this->isFullyPaid(),
            'requires_deposit' => $this->requiresDeposit(),

            // Relationships
            'customer' => $this->whenLoaded('customer', function () {
                return [
                    'id' => $this->customer->id,
                    'name' => $this->customer->name,
                    'email' => $this->customer->email,
                    'phone' => $this->customer->phone,
                    'avatar_url' => $this->customer->avatar_url,
                ];
            }),

            'vendor' => $this->whenLoaded('vendor', function () {
                return [
                    'id' => $this->vendor->id,
                    'name' => $this->vendor->name,
                    'business_name' => $this->vendor->business_name,
                    'email' => $this->vendor->email,
                    'phone' => $this->vendor->phone,
                    'avatar_url' => $this->vendor->avatar_url,
                    'slug' => $this->vendor->slug,
                ];
            }),

            'service' => $this->whenLoaded('service', function () {
                return [
                    'id' => $this->service->id,
                    'title' => $this->service->title,
                    'slug' => $this->service->slug,
                    'price' => $this->service->price,
                    'currency' => $this->service->currency,
                    'duration_minutes' => $this->service->duration_minutes,
                    'category' => $this->service->category,
                    'description' => $this->service->description,
                    'images' => $this->service->images,
                ];
            }),

            'cancelled_by' => $this->whenLoaded('cancelledBy', function () {
                return [
                    'id' => $this->cancelledBy->id,
                    'name' => $this->cancelledBy->name,
                ];
            }),

            'payments' => $this->whenLoaded('payments', function () {
                return $this->payments->map(function ($payment) {
                    return [
                        'id' => $payment->id,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'payment_method' => $payment->payment_method,
                        'payment_provider' => $payment->payment_provider,
                        'status' => $payment->status,
                        'processed_at' => $payment->processed_at,
                        'formatted_amount' => $payment->formatted_amount,
                    ];
                });
            }),

            'review' => $this->whenLoaded('review', function () {
                return $this->review ? [
                    'id' => $this->review->id,
                    'rating' => $this->review->rating,
                    'review_text' => $this->review->review_text,
                    'star_rating' => $this->review->star_rating,
                    'is_positive' => $this->review->is_positive,
                    'created_at' => $this->review->created_at,
                    'formatted_date' => $this->review->formatted_date,
                ] : null;
            }),
        ];
    }

    /**
     * Check if the current user is the vendor or customer of this booking.
     */
    private function isVendorOrCustomer(Request $request): bool
    {
        $user = $request->user();
        return $user && ($this->vendor_id === $user->id || $this->customer_id === $user->id);
    }
}
