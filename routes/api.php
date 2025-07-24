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
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\EarningsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReviewsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\YocoPaymentController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\VendorVerificationController;
use App\Http\Controllers\Admin\VendorVerificationController as AdminVendorVerificationController;

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
    Route::get('/services/{userSlug}/{serviceSlug}/available-slots', [ServiceController::class, 'availableSlots'])
        ->where(['userSlug' => '[a-z0-9\-]+', 'serviceSlug' => '[a-z0-9\-]+']);

    // Service categories
    Route::get('/service-categories', [ServiceController::class, 'categories']);

    // Health check endpoints
    Route::get('/health', [HealthController::class, 'check']);
    Route::get('/health/simple', [HealthController::class, 'simple']);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    // Public booking routes (supports both authenticated and guest users)
    Route::post('/bookings', [BookingController::class, 'store'])
        ->middleware('throttle:10,1'); // 10 booking attempts per minute
    Route::get('/bookings/{booking}/public', [BookingController::class, 'showPublic'])
        ->middleware('throttle:60,1'); // 60 requests per minute

    // Public Yoco payment routes
    Route::prefix('payments/yoco')->group(function () {
        Route::get('/public-key', [YocoPaymentController::class, 'getPublicKey']);
        Route::post('/webhook', [YocoPaymentController::class, 'handleWebhook'])
            ->middleware('throttle:60,1'); // 60 webhook requests per minute
    });

    // Protected auth routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
        
        // Dashboard route
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Profile management routes
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::put('/password', [ProfileController::class, 'updatePassword']);
            Route::post('/avatar', [ProfileController::class, 'updateAvatar']);
            Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);
            Route::get('/stats', [ProfileController::class, 'stats']);
        });
        
        // Vendor verification routes
        Route::prefix('vendor/verification')->name('vendor.verification.')->group(function () {
            Route::get('/status', [VendorVerificationController::class, 'status'])->name('status');
            Route::post('/start', [VendorVerificationController::class, 'start'])->name('start');
            Route::post('/resend-email', [VendorVerificationController::class, 'resendEmailVerification'])->name('resend-email');
            Route::get('/requirements', [VendorVerificationController::class, 'requirements'])->name('requirements');
            Route::get('/documents', [VendorVerificationController::class, 'documents'])->name('documents');
            
            // Document upload routes
            Route::post('/identity-document', [VendorVerificationController::class, 'uploadIdentityDocument'])
                ->name('upload-identity');
            Route::post('/liveness-photo', [VendorVerificationController::class, 'uploadLivenessPhoto'])
                ->name('upload-liveness');
            Route::post('/business-document', [VendorVerificationController::class, 'uploadBusinessDocument'])
                ->name('upload-business');
            
            Route::post('/submit', [VendorVerificationController::class, 'submitForReview'])->name('submit');
        });

        // Email verification route (accessible via signed URL)
        Route::get('/vendor/verification/{id}/verify-email/{token}', [VendorVerificationController::class, 'verifyEmail'])
            ->name('vendor.verification.verify-email');

        // Service management routes (require vendor verification for vendors)
        Route::prefix('services')->middleware('vendor.verified')->group(function () {
            Route::get('/my-services', [ServiceController::class, 'myServices']);
            Route::post('/', [ServiceController::class, 'store'])
                ->middleware('throttle:60,1'); // 60 requests per minute
            Route::get('/{id}/edit', [ServiceController::class, 'showById']); // For editing
            Route::put('/{id}', [ServiceController::class, 'update'])
                ->middleware('throttle:60,1'); // 60 requests per minute
            Route::delete('/{id}', [ServiceController::class, 'destroy'])
                ->middleware('throttle:60,1'); // 60 requests per minute
            Route::get('/{service}/analytics', [ServiceController::class, 'analytics']);
        });
        
        // Booking management routes (authenticated users only)
        Route::prefix('bookings')->group(function () {
            Route::get('/', [BookingController::class, 'index']);
            Route::get('/{booking}', [BookingController::class, 'show']);
            Route::put('/{booking}', [BookingController::class, 'update']);
            Route::post('/{booking}/cancel', [BookingController::class, 'cancel']);
            Route::post('/{booking}/reschedule', [BookingController::class, 'reschedule']);
            
            // Vendor-only booking actions (require verification)
            Route::middleware('vendor.verified')->group(function () {
                Route::post('/{booking}/confirm', [BookingController::class, 'confirm']);
                Route::post('/{booking}/in-progress', [BookingController::class, 'markInProgress']);
                Route::post('/{booking}/complete', [BookingController::class, 'complete']);
            });
        });

        // Availability routes (vendor-only - require verification)
        Route::prefix('availability')->middleware('vendor.verified')->group(function () {
            Route::get('/', [AvailabilityController::class, 'index']);
            Route::post('/', [AvailabilityController::class, 'store']);
            Route::get('/{availability}', [AvailabilityController::class, 'show']);
            Route::put('/{availability}', [AvailabilityController::class, 'update']);
            Route::delete('/{availability}', [AvailabilityController::class, 'destroy']);
            Route::get('/time-slots', [AvailabilityController::class, 'getTimeSlots']);
            Route::get('/weekly-overview', [AvailabilityController::class, 'getWeeklyOverview']);
            Route::post('/bulk-update', [AvailabilityController::class, 'bulkUpdate']);
        });
        
        // Analytics routes (vendor-only - require verification)
        Route::prefix('analytics')->middleware('vendor.verified')->group(function () {
            Route::get('/', [AnalyticsController::class, 'index']);
            Route::get('/summary', [AnalyticsController::class, 'summary']);
            Route::post('/export', [AnalyticsController::class, 'export']);
        });
        
        // Earnings routes (vendor-only - require verification)
        Route::prefix('earnings')->middleware('vendor.verified')->group(function () {
            Route::get('/', [EarningsController::class, 'index']);
            Route::get('/summary', [EarningsController::class, 'summary']);
            Route::get('/transactions', [EarningsController::class, 'transactions']);
            Route::get('/payout-methods', [EarningsController::class, 'payoutMethods']);
            Route::post('/request-payout', [EarningsController::class, 'requestPayout']);
        });
        
        // Reviews routes
        Route::prefix('reviews')->group(function () {
            Route::get('/', [ReviewsController::class, 'index']);
            
            // Vendor-only review actions (require verification)
            Route::middleware('vendor.verified')->group(function () {
                Route::get('/analytics', [ReviewsController::class, 'analytics']);
                Route::get('/services', [ReviewsController::class, 'services']);
                Route::post('/{review}/respond', [ReviewsController::class, 'respond']);
                Route::put('/{review}/response', [ReviewsController::class, 'updateResponse']);
                Route::delete('/{review}/response', [ReviewsController::class, 'deleteResponse']);
            });
        });
        
        // Payment routes
        Route::prefix('payments')->group(function () {
            Route::get('/', [PaymentController::class, 'index']);
            Route::get('/history', [PaymentController::class, 'history']);
            Route::get('/transactions', [PaymentController::class, 'transactions']);
            Route::get('/analytics', [PaymentController::class, 'analytics']);
            Route::get('/payment-methods', [PaymentController::class, 'paymentMethods']);
            Route::post('/payment-methods', [PaymentController::class, 'addPaymentMethod']);
            Route::put('/payment-methods/{paymentMethod}/default', [PaymentController::class, 'setDefaultPaymentMethod']);
            Route::delete('/payment-methods/{paymentMethod}', [PaymentController::class, 'deletePaymentMethod']);
            
            // Yoco payment integration
            Route::prefix('yoco')->group(function () {
                Route::post('/bookings/{booking}/create-intent', [YocoPaymentController::class, 'createPaymentIntent']);
                Route::post('/confirm', [YocoPaymentController::class, 'confirmPayment']);
                Route::get('/{payment}/status', [YocoPaymentController::class, 'getPaymentStatus']);
                Route::post('/{payment}/refund', [YocoPaymentController::class, 'createRefund']);
            });
        });

        // Favorites routes
        Route::prefix('favorites')->group(function () {
            Route::get('/', [FavoriteController::class, 'index']);
            Route::post('/', [FavoriteController::class, 'store']);
            Route::delete('/{favorite}', [FavoriteController::class, 'destroy']);
            Route::post('/toggle', [FavoriteController::class, 'toggle']);
            Route::get('/check/{service}', [FavoriteController::class, 'check']);
            Route::post('/bulk-delete', [FavoriteController::class, 'bulkDelete']);
            Route::post('/clear', [FavoriteController::class, 'clear']);
            Route::get('/count', [FavoriteController::class, 'count']);
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
        
        // Admin routes (require admin role)
        Route::middleware(['role:admin'])->prefix('admin')->group(function () {
            // Vendor verification management
            Route::prefix('vendor-verifications')->group(function () {
                Route::get('/', [AdminVendorVerificationController::class, 'index']);
                Route::get('/statistics', [AdminVendorVerificationController::class, 'statistics']);
                Route::get('/{id}', [AdminVendorVerificationController::class, 'show']);
                Route::post('/{id}/approve', [AdminVendorVerificationController::class, 'approve']);
                Route::post('/{id}/reject', [AdminVendorVerificationController::class, 'reject']);
                Route::post('/bulk-approve', [AdminVendorVerificationController::class, 'bulkApprove']);
            });
        });
    });
});

// Backward compatibility - redirect to v1
Route::post('/register', fn() => redirect('/api/v1/register'));
Route::post('/login', fn() => redirect('/api/v1/login'));
Route::post('/logout', fn() => redirect('/api/v1/logout'));
Route::get('/user', fn() => redirect('/api/v1/user'));