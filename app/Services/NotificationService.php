<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send a notification to a user.
     */
    public function notify(User $user, string $type, array $data = [], array $options = []): Notification
    {
        $template = $this->getTemplate($type, $data);
        
        $notification = $user->notifications()->create([
            'type' => $type,
            'data' => [
                'title' => $template['title'],
                'message' => $template['message'],
                ...$data
            ],
            'priority' => $options['priority'] ?? 'normal',
            'channel' => $options['channel'] ?? 'database',
            'metadata' => [
                'action_url' => $options['action_url'] ?? $template['action_url'] ?? null,
                'icon' => $options['icon'] ?? $template['icon'] ?? null,
                ...$options['metadata'] ?? []
            ],
            'expires_at' => $options['expires_at'] ?? null,
        ]);

        Log::info('Notification sent', [
            'user_id' => $user->id,
            'notification_id' => $notification->id,
            'type' => $type
        ]);

        return $notification;
    }

    /**
     * Send notification to multiple users.
     */
    public function notifyMany(array $users, string $type, array $data = [], array $options = []): array
    {
        $notifications = [];
        
        foreach ($users as $user) {
            $notifications[] = $this->notify($user, $type, $data, $options);
        }
        
        return $notifications;
    }

    /**
     * Send welcome notification to new user.
     */
    public function sendWelcomeNotification(User $user): Notification
    {
        return $this->notify($user, 'welcome', [
            'user_name' => $user->name
        ], [
            'priority' => 'high',
            'action_url' => '/dashboard'
        ]);
    }

    /**
     * Send booking confirmation notification.
     */
    public function sendBookingConfirmation(User $user, array $bookingData): Notification
    {
        return $this->notify($user, 'booking_confirmed', [
            'booking_id' => $bookingData['id'],
            'service_name' => $bookingData['service_name'],
            'date' => $bookingData['date'],
            'time' => $bookingData['time'],
        ], [
            'priority' => 'high',
            'action_url' => "/dashboard/bookings/{$bookingData['id']}"
        ]);
    }

    /**
     * Send booking cancellation notification.
     */
    public function sendBookingCancellation(User $user, array $bookingData): Notification
    {
        return $this->notify($user, 'booking_cancelled', [
            'booking_id' => $bookingData['id'],
            'service_name' => $bookingData['service_name'],
            'reason' => $bookingData['reason'] ?? 'No reason provided',
        ], [
            'priority' => 'high',
            'action_url' => '/dashboard/bookings'
        ]);
    }

    /**
     * Send payment received notification.
     */
    public function sendPaymentReceived(User $user, array $paymentData): Notification
    {
        return $this->notify($user, 'payment_received', [
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'] ?? 'ZAR',
            'payment_id' => $paymentData['id'],
        ], [
            'priority' => 'normal',
            'action_url' => "/dashboard/payments/{$paymentData['id']}"
        ]);
    }

    /**
     * Send payment failed notification.
     */
    public function sendPaymentFailed(User $user, array $paymentData): Notification
    {
        return $this->notify($user, 'payment_failed', [
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'] ?? 'ZAR',
            'reason' => $paymentData['reason'] ?? 'Payment declined',
        ], [
            'priority' => 'urgent',
            'action_url' => '/dashboard/payments'
        ]);
    }

    /**
     * Send new review notification.
     */
    public function sendNewReview(User $user, array $reviewData): Notification
    {
        return $this->notify($user, 'new_review', [
            'reviewer_name' => $reviewData['reviewer_name'],
            'rating' => $reviewData['rating'],
            'service_name' => $reviewData['service_name'],
        ], [
            'priority' => 'normal',
            'action_url' => "/dashboard/reviews/{$reviewData['id']}"
        ]);
    }

    /**
     * Send booking notification based on event type.
     */
    public function sendBookingNotification($booking, string $eventType): array
    {
        $notifications = [];
        
        // Ensure booking is loaded with relationships
        if (!$booking->relationLoaded('customer')) {
            $booking->load(['customer', 'vendor', 'service']);
        }
        
        $bookingData = [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'service_name' => $booking->service->title ?? 'Service',
            'date' => $booking->scheduled_at ? \Carbon\Carbon::parse($booking->scheduled_at)->format('Y-m-d') : 'TBD',
            'time' => $booking->scheduled_at ? \Carbon\Carbon::parse($booking->scheduled_at)->format('H:i') : 'TBD',
            'customer_name' => $booking->customer->name ?? $booking->guest_name ?? 'Customer',
            'vendor_name' => $booking->vendor->name ?? 'Vendor'
        ];
        
        switch ($eventType) {
            case 'created':
                // Notify customer (only if they have an account)
                if ($booking->customer) {
                    $notifications[] = $this->sendBookingConfirmation($booking->customer, $bookingData);
                }
                // Notify vendor about new booking
                $notifications[] = $this->notify($booking->vendor, 'new_booking', $bookingData, [
                    'priority' => 'high',
                    'action_url' => "/dashboard/bookings/{$booking->id}"
                ]);
                break;
                
            case 'updated':
                // Notify both parties about update (only if customer has account)
                if ($booking->customer) {
                    $notifications[] = $this->notify($booking->customer, 'booking_updated', $bookingData, [
                        'priority' => 'normal',
                        'action_url' => "/dashboard/bookings/{$booking->id}"
                    ]);
                }
                $notifications[] = $this->notify($booking->vendor, 'booking_updated', $bookingData, [
                    'priority' => 'normal',
                    'action_url' => "/dashboard/bookings/{$booking->id}"
                ]);
                break;
                
            case 'cancelled':
                // Use existing cancellation method (only if customer has account)
                if ($booking->customer) {
                    $notifications[] = $this->sendBookingCancellation($booking->customer, $bookingData);
                }
                $notifications[] = $this->sendBookingCancellation($booking->vendor, $bookingData);
                break;
                
            case 'confirmed':
                // Notify both parties about confirmation (only if customer has account)
                if ($booking->customer) {
                    $notifications[] = $this->notify($booking->customer, 'booking_confirmed', $bookingData, [
                        'priority' => 'high',
                        'action_url' => "/dashboard/bookings/{$booking->id}"
                    ]);
                }
                $notifications[] = $this->notify($booking->vendor, 'booking_confirmed', $bookingData, [
                    'priority' => 'high',
                    'action_url' => "/dashboard/bookings/{$booking->id}"
                ]);
                break;
                
            case 'completed':
                // Notify both parties about completion (only if customer has account)
                if ($booking->customer) {
                    $notifications[] = $this->notify($booking->customer, 'booking_completed', $bookingData, [
                        'priority' => 'normal',
                        'action_url' => "/dashboard/bookings/{$booking->id}"
                    ]);
                }
                $notifications[] = $this->notify($booking->vendor, 'booking_completed', $bookingData, [
                    'priority' => 'normal',
                    'action_url' => "/dashboard/bookings/{$booking->id}"
                ]);
                break;
                
            case 'rescheduled':
                // Notify both parties about reschedule (only if customer has account)
                if ($booking->customer) {
                    $notifications[] = $this->notify($booking->customer, 'booking_rescheduled', $bookingData, [
                        'priority' => 'high',
                        'action_url' => "/dashboard/bookings/{$booking->id}"
                    ]);
                }
                $notifications[] = $this->notify($booking->vendor, 'booking_rescheduled', $bookingData, [
                    'priority' => 'high',
                    'action_url' => "/dashboard/bookings/{$booking->id}"
                ]);
                break;
                
            default:
                Log::warning('Unknown booking notification event type', [
                    'event_type' => $eventType,
                    'booking_id' => $booking->id
                ]);
                break;
        }
        
        return $notifications;
    }

    /**
     * Send profile updated notification.
     */
    public function sendProfileUpdated(User $user): Notification
    {
        return $this->notify($user, 'profile_updated', [
            'user_name' => $user->name
        ], [
            'priority' => 'low',
            'action_url' => '/dashboard/profile'
        ]);
    }

    /**
     * Get notification template based on type.
     */
    private function getTemplate(string $type, array $data = []): array
    {
        return match($type) {
            'welcome' => [
                'title' => 'Welcome to ORDO!',
                'message' => "Hi {$data['user_name']}, welcome to ORDO! We're excited to have you on board.",
                'icon' => 'heart',
                'action_url' => '/dashboard'
            ],
            'booking_confirmed' => [
                'title' => 'Booking Confirmed',
                'message' => "Your booking for {$data['service_name']} on {$data['date']} at {$data['time']} has been confirmed.",
                'icon' => 'check-circle',
            ],
            'booking_cancelled' => [
                'title' => 'Booking Cancelled',
                'message' => "Your booking for {$data['service_name']} has been cancelled. Reason: {$data['reason']}",
                'icon' => 'x-circle',
            ],
            'payment_received' => [
                'title' => 'Payment Received',
                'message' => "We've received your payment of {$data['currency']} {$data['amount']}. Thank you!",
                'icon' => 'credit-card',
            ],
            'payment_failed' => [
                'title' => 'Payment Failed',
                'message' => "Your payment of {$data['currency']} {$data['amount']} failed. {$data['reason']}",
                'icon' => 'exclamation-triangle',
            ],
            'new_review' => [
                'title' => 'New Review',
                'message' => "{$data['reviewer_name']} left you a {$data['rating']}-star review for {$data['service_name']}.",
                'icon' => 'star',
            ],
            'profile_updated' => [
                'title' => 'Profile Updated',
                'message' => "Hi {$data['user_name']}, your profile has been successfully updated.",
                'icon' => 'user',
            ],
            default => [
                'title' => 'Notification',
                'message' => 'You have a new notification.',
                'icon' => 'bell',
            ]
        };
    }
}