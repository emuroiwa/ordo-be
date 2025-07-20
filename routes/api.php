<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Api\AvailabilityController;

// API Version 1
Route::prefix('v1')->group(function () {
    
    // Public auth routes
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1'); // 5 attempts per minute
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1'); // 5 attempts per minute

    // Password reset routes
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLinkEmail'])
        ->middleware('throttle:3,1'); // 3 attempts per minute
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:3,1'); // 3 attempts per minute

    // Public service routes
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/search', [ServiceController::class, 'search']);
    Route::get('/services/{userSlug}/{serviceSlug}', [ServiceController::class, 'show'])
        ->where(['userSlug' => '[a-z0-9\-]+', 'serviceSlug' => '[a-z0-9\-]+']);

    // Service categories
    Route::get('/service-categories', [ServiceController::class, 'categories']);

    // Public booking routes (supports both authenticated and guest users)
    Route::post('/bookings', [BookingController::class, 'store'])
        ->middleware('throttle:10,1'); // 10 booking attempts per minute
    Route::get('/bookings/{booking}/public', [BookingController::class, 'showPublic'])
        ->middleware('throttle:60,1'); // 60 requests per minute

    // Protected auth routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);

        // Profile management routes
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::put('/password', [ProfileController::class, 'updatePassword']);
            Route::post('/avatar', [ProfileController::class, 'updateAvatar']);
            Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);
            Route::get('/stats', [ProfileController::class, 'stats']);
        });
        
        // Service management routes
        Route::prefix('services')->group(function () {
            Route::get('/my-services', [ServiceController::class, 'myServices']);
            Route::post('/', [ServiceController::class, 'store'])
                ->middleware('throttle:60,1'); // 60 requests per minute
            Route::get('/{id}/edit', [ServiceController::class, 'showById']); // For editing
            Route::put('/{service}', [ServiceController::class, 'update'])
                ->middleware('throttle:60,1'); // 60 requests per minute
            Route::delete('/{service}', [ServiceController::class, 'destroy'])
                ->middleware('throttle:60,1'); // 60 requests per minute
            Route::get('/{service}/analytics', [ServiceController::class, 'analytics']);
        });
        
        // Booking management routes (authenticated users only)
        Route::prefix('bookings')->group(function () {
            Route::get('/', [BookingController::class, 'index']);
            Route::get('/{booking}', [BookingController::class, 'show']);
            Route::put('/{booking}', [BookingController::class, 'update']);
            Route::post('/{booking}/cancel', [BookingController::class, 'cancel']);
            Route::post('/{booking}/confirm', [BookingController::class, 'confirm']);
            Route::post('/{booking}/in-progress', [BookingController::class, 'markInProgress']);
            Route::post('/{booking}/complete', [BookingController::class, 'complete']);
            Route::post('/{booking}/reschedule', [BookingController::class, 'reschedule']);
        });

        // Availability routes
        Route::prefix('availability')->group(function () {
            Route::get('/', [AvailabilityController::class, 'index']);
            Route::post('/', [AvailabilityController::class, 'store']);
            Route::get('/{availability}', [AvailabilityController::class, 'show']);
            Route::put('/{availability}', [AvailabilityController::class, 'update']);
            Route::delete('/{availability}', [AvailabilityController::class, 'destroy']);
            Route::get('/time-slots', [AvailabilityController::class, 'getTimeSlots']);
            Route::get('/weekly-overview', [AvailabilityController::class, 'getWeeklyOverview']);
            Route::post('/bulk-update', [AvailabilityController::class, 'bulkUpdate']);
        });
        
        // Notification routes
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/recent', [NotificationController::class, 'recent']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('/', [NotificationController::class, 'store']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
            Route::post('/bulk-action', [NotificationController::class, 'bulkAction']);
            Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead']);
            Route::patch('/{notification}/unread', [NotificationController::class, 'markAsUnread']);
            Route::delete('/{notification}', [NotificationController::class, 'destroy']);
            
            // Test route for creating sample notifications
            Route::post('/test-samples', function() {
                $user = Auth::user();
                $notificationService = new \App\Services\NotificationService();
                
                // Create sample notifications
                $notificationService->sendBookingConfirmation($user, [
                    'id' => 'booking_123',
                    'service_name' => 'Professional Haircut',
                    'date' => '2025-07-20',
                    'time' => '14:00'
                ]);
                
                $notificationService->sendPaymentReceived($user, [
                    'id' => 'payment_456',
                    'amount' => '150.00',
                    'currency' => 'ZAR'
                ]);
                
                $notificationService->sendNewReview($user, [
                    'id' => 'review_789',
                    'reviewer_name' => 'John Doe',
                    'rating' => 5,
                    'service_name' => 'Beard Trim'
                ]);
                
                return response()->json(['message' => 'Sample notifications created']);
            });
        });
    });
});

// Backward compatibility - redirect to v1
Route::post('/register', fn() => redirect('/api/v1/register'));
Route::post('/login', fn() => redirect('/api/v1/login'));
Route::post('/logout', fn() => redirect('/api/v1/logout'));
Route::get('/user', fn() => redirect('/api/v1/user'));