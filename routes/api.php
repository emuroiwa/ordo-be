<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\NotificationController;

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

    // Protected auth routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
        
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