<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Create a new booking.
     */
    public function createBooking(array $data, ?User $customer = null): Booking
    {
        return DB::transaction(function () use ($data, $customer) {
            $service = Service::findOrFail($data['service_id']);
            
            // Handle customer lookup for guest bookings
            $customerId = null;
            $guestEmail = null;
            $guestPhone = null;
            $guestName = null;
            
            if ($customer) {
                // Authenticated user
                $customerId = $customer->id;
            } else {
                // Guest booking - check if user exists by email or phone
                $existingUser = User::where('email', $data['guest_email'])
                    ->orWhere('phone', $data['guest_phone'])
                    ->first();
                
                if ($existingUser) {
                    // Link to existing user
                    $customerId = $existingUser->id;
                } else {
                    // Store guest information
                    $guestEmail = $data['guest_email'];
                    $guestPhone = $data['guest_phone'];
                    $guestName = $data['guest_name'];
                }
            }
            
            // Validate availability
            $this->validateAvailability($service, $data['scheduled_at'], $data['duration_minutes']);
            
            // Calculate pricing
            $pricing = $this->calculatePricing($service, $data['duration_minutes']);
            
            // Create booking
            $booking = Booking::create([
                'customer_id' => $customerId,
                'vendor_id' => $service->user_id,
                'service_id' => $service->id,
                'scheduled_at' => $data['scheduled_at'],
                'duration_minutes' => $data['duration_minutes'],
                'total_amount' => $pricing['total'],
                'deposit_amount' => $pricing['deposit'],
                'currency' => $service->currency ?? 'ZAR',
                'customer_notes' => $data['customer_notes'] ?? null,
                'location_type' => $data['location_type'],
                'service_address' => $data['service_address'] ?? null,
                'status' => 'pending',
                'payment_status' => 'pending',
                // Guest information
                'guest_email' => $guestEmail,
                'guest_phone' => $guestPhone,
                'guest_name' => $guestName,
            ]);

            // Send notifications
            $this->notificationService->sendBookingNotification($booking, 'created');

            return $booking->load(['customer', 'vendor', 'service']);
        });
    }

    /**
     * Update an existing booking.
     */
    public function updateBooking(Booking $booking, array $data, User $user): Booking
    {
        return DB::transaction(function () use ($booking, $data, $user) {
            // Determine allowed fields based on user role and booking status
            $allowedFields = [];
            
            $isCustomer = $booking->customer_id === $user->id;
            $isVendor = $booking->vendor_id === $user->id;
            
            // If user has both roles, determine intent based on which fields are being updated
            if ($isCustomer && $isVendor) {
                // Check which type of fields are being updated
                $customerFields = ['customer_notes', 'service_address'];
                $vendorFields = ['vendor_notes'];
                
                $hasCustomerFields = !empty(array_intersect(array_keys($data), $customerFields));
                $hasVendorFields = !empty(array_intersect(array_keys($data), $vendorFields));
                
                if ($hasVendorFields && !$hasCustomerFields) {
                    // Updating vendor fields only
                    $allowedFields = ['vendor_notes'];
                } elseif ($hasCustomerFields && !$hasVendorFields) {
                    // Updating customer fields only
                    if ($booking->status === 'pending') {
                        $allowedFields = ['customer_notes', 'service_address'];
                    } else {
                        $allowedFields = ['customer_notes'];
                    }
                } else {
                    // Mixed or no clear intent - allow both
                    $baseCustomerFields = $booking->status === 'pending' ? ['customer_notes', 'service_address'] : ['customer_notes'];
                    $allowedFields = array_merge($baseCustomerFields, ['vendor_notes']);
                }
            } elseif ($isCustomer) {
                if ($booking->status === 'pending') {
                    $allowedFields = ['customer_notes', 'service_address'];
                } else {
                    $allowedFields = ['customer_notes'];
                }
            } elseif ($isVendor) {
                $allowedFields = ['vendor_notes'];
            }

            $updateData = array_intersect_key($data, array_flip($allowedFields));
            
            // Debug logging
            Log::info('Booking update debug', [
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'user_is_customer' => $booking->customer_id === $user->id,
                'user_is_vendor' => $booking->vendor_id === $user->id,
                'booking_status' => $booking->status,
                'allowed_fields' => $allowedFields,
                'incoming_data' => $data,
                'filtered_data_before' => $updateData
            ]);
            
            // Filter out null values but allow empty strings (for clearing fields)
            $updateData = array_filter($updateData, function($value, $key) use ($data) {
                // Allow the field if it exists in the original data (including null for clearing fields)
                return array_key_exists($key, $data);
            }, ARRAY_FILTER_USE_BOTH);
            
            Log::info('Booking update filtered data', [
                'filtered_data_after' => $updateData,
                'is_empty' => empty($updateData)
            ]);
            
            if (empty($updateData)) {
                throw new \Exception('No valid fields to update.');
            }

            $booking->update($updateData);

            // Send notification if significant changes
            if (isset($updateData['service_address'])) {
                $this->notificationService->sendBookingNotification($booking, 'updated');
            }

            return $booking->fresh();
        });
    }

    /**
     * Cancel a booking.
     */
    public function cancelBooking(Booking $booking, ?string $reason, User $user): Booking
    {
        return DB::transaction(function () use ($booking, $reason, $user) {
            // Check cancellation policy
            if (!$booking->can_be_cancelled) {
                throw new \Exception('This booking cannot be cancelled at this time.');
            }

            $booking->cancel($reason, $user->id);

            // Handle refunds if applicable
            $this->handleCancellationRefund($booking);

            // Send notifications
            $this->notificationService->sendBookingNotification($booking, 'cancelled');

            return $booking;
        });
    }

    /**
     * Confirm a booking (vendor only).
     */
    public function confirmBooking(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            if ($booking->status !== 'pending') {
                throw new \Exception('Only pending bookings can be confirmed.');
            }

            $booking->confirm();

            // Send notifications
            $this->notificationService->sendBookingNotification($booking, 'confirmed');

            return $booking;
        });
    }

    /**
     * Complete a booking.
     */
    public function completeBooking(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            if (!in_array($booking->status, ['confirmed', 'in_progress'])) {
                throw new \Exception('Only confirmed or in-progress bookings can be completed.');
            }

            $booking->complete();

            // Send notifications
            $this->notificationService->sendBookingNotification($booking, 'completed');

            return $booking;
        });
    }

    /**
     * Reschedule a booking.
     */
    public function rescheduleBooking(Booking $booking, string $newDateTime): Booking
    {
        return DB::transaction(function () use ($booking, $newDateTime) {
            $newDate = Carbon::parse($newDateTime);
            
            // Validate new time slot
            $this->validateAvailability($booking->service, $newDate, $booking->duration_minutes, $booking->id);

            $booking->reschedule($newDate);

            // Send notifications
            $this->notificationService->sendBookingNotification($booking, 'rescheduled');

            return $booking;
        });
    }

    /**
     * Validate booking availability.
     */
    protected function validateAvailability(Service $service, $scheduledAt, int $durationMinutes, ?string $excludeBookingId = null): void
    {
        $scheduledAt = Carbon::parse($scheduledAt);
        
        // Check if scheduled time is in the future
        if ($scheduledAt->isPast()) {
            throw new \Exception('Booking time must be in the future.');
        }

        // Check if it's during business hours (simplified - you can enhance this)
        $dayOfWeek = $scheduledAt->dayOfWeek;
        $availabilitySlots = $service->availabilitySlots()
            ->where('day_of_week', $dayOfWeek)
            ->where('is_available', true)
            ->get();

        Log::info('Checking availability for service ID ' . $service->availabilitySlots()
            ->where('day_of_week', $dayOfWeek)
            ->where('is_available', true)->toSql());
        Log::info('Checking availability for service ID ' . $service->id . ' on ' . $scheduledAt->format('Y-m-d H:i'));
        if ($availabilitySlots->isEmpty()) {
            throw new \Exception('Service is not available on ' . $scheduledAt->format('l') . '.');
        }

        // Check if the time falls within available slots
        $timeAvailable = false;
        foreach ($availabilitySlots as $slot) {
            if ($slot->isAvailableForDate($scheduledAt, $durationMinutes)) {
                $timeAvailable = true;
                break;
            }
        }

        if (!$timeAvailable) {
            throw new \Exception('Selected time slot is not available.');
        }

        // Check for conflicting bookings
        $query = Booking::where('vendor_id', $service->user_id)
            ->where('scheduled_at', '<', $scheduledAt->copy()->addMinutes($durationMinutes))
            ->where('scheduled_at', '>', $scheduledAt->copy()->subMinutes($durationMinutes))
            ->whereIn('status', ['pending', 'confirmed', 'in_progress']);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        if ($query->exists()) {
            throw new \Exception('Time slot conflicts with existing booking.');
        }
    }

    /**
     * Calculate booking pricing.
     */
    protected function calculatePricing(Service $service, int $durationMinutes): array
    {
        // Base price calculation
        $serviceDuration = $service->duration_minutes ?? 60;
        $basePrice = $service->base_price ?? 0;
        
        // Ensure we don't divide by zero
        if ($serviceDuration <= 0) {
            $serviceDuration = 60; // Default to 60 minutes
        }
        
        $totalAmount = ($basePrice / $serviceDuration) * $durationMinutes;

        // Calculate deposit (30% of total)
        $depositAmount = $totalAmount * 0.30;

        return [
            'total' => round($totalAmount, 2),
            'deposit' => round($depositAmount, 2),
        ];
    }

    /**
     * Handle cancellation refunds.
     */
    protected function handleCancellationRefund(Booking $booking): void
    {
        $hoursUntilBooking = $booking->scheduled_at->diffInHours(now());
        
        // Refund policy:
        // 24+ hours: Full refund
        // 12-24 hours: 50% refund
        // <12 hours: No refund
        
        $refundPercentage = 0;
        if ($hoursUntilBooking >= 24) {
            $refundPercentage = 100;
        } elseif ($hoursUntilBooking >= 12) {
            $refundPercentage = 50;
        }

        if ($refundPercentage > 0) {
            // Process refund logic here
            // This would integrate with your payment provider
            $refundAmount = ($booking->getTotalPaidAmount() * $refundPercentage) / 100;
            
            // Create refund record
            // BookingPayment::create([...refund details...]);
        }
    }
}
