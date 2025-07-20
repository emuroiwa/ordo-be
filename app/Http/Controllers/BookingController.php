<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Service;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    protected BookingService $bookingService;

    public function __construct(BookingService $bookingService)
    {
        // Middleware is now handled in routes/api.php
        $this->bookingService = $bookingService;
    }

    /**
     * Display a listing of bookings for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = $request->get('per_page', 15);
        $status = $request->get('status');
        $type = $request->get('type', 'all'); // all, customer, vendor

        $query = Booking::query();

        // Filter by user role
        if ($type === 'customer') {
            $query->forCustomer($user->id);
        } elseif ($type === 'vendor') {
            $query->forVendor($user->id);
        } else {
            // All bookings for this user (both as customer and vendor)
            $query->where(function ($q) use ($user) {
                $q->where('customer_id', $user->id)
                  ->orWhere('vendor_id', $user->id);
            });
        }

        // Filter by status if provided
        if ($status) {
            $query->where('status', $status);
        }

        // Order by scheduled date
        $query->with(['customer', 'vendor', 'service', 'review'])
              ->orderBy('scheduled_at', 'desc');

        $bookings = $query->paginate($perPage);

        return BookingResource::collection($bookings);
    }

    /**
     * Store a newly created booking.
     */
    public function store(CreateBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->createBooking($request->validated(), $request->user());
            
            return response()->json([
                'message' => 'Booking created successfully',
                'booking' => new BookingResource($booking),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create booking',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Display the specified booking.
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        // Check if user has access to this booking
        if ($booking->customer_id !== $user->id && $booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $booking->load(['customer', 'vendor', 'service', 'payments', 'review']);

        return response()->json([
            'booking' => new BookingResource($booking),
        ]);
    }

    /**
     * Display the specified booking for public access (guest users).
     */
    public function showPublic(Request $request, Booking $booking): JsonResponse
    {
        // Load relationships but don't check authorization for public access
        $booking->load(['customer', 'vendor', 'service', 'payments', 'review']);

        return response()->json([
            'booking' => new BookingResource($booking),
        ]);
    }

    /**
     * Update the specified booking.
     */
    public function update(UpdateBookingRequest $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        // Check if user has access to this booking
        if ($booking->customer_id !== $user->id && $booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $updatedBooking = $this->bookingService->updateBooking($booking, $request->validated(), $user);
            
            return response()->json([
                'message' => 'Booking updated successfully',
                'booking' => new BookingResource($updatedBooking),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update booking',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel a booking.
     */
    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        // Check if user has access to this booking
        if ($booking->customer_id !== $user->id && $booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $this->bookingService->cancelBooking($booking, $request->get('reason'), $user);
            
            return response()->json([
                'message' => 'Booking cancelled successfully',
                'booking' => new BookingResource($booking->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel booking',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Confirm a booking (vendor only).
     */
    public function confirm(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        // Only vendor can confirm
        if ($booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $this->bookingService->confirmBooking($booking);
            
            return response()->json([
                'message' => 'Booking confirmed successfully',
                'booking' => new BookingResource($booking->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to confirm booking',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mark booking as in progress (vendor only).
     */
    public function markInProgress(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if ($booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $booking->markInProgress();
            
            return response()->json([
                'message' => 'Booking marked as in progress',
                'booking' => new BookingResource($booking->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update booking status',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Complete a booking (vendor only).
     */
    public function complete(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        if ($booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $this->bookingService->completeBooking($booking);
            
            return response()->json([
                'message' => 'Booking completed successfully',
                'booking' => new BookingResource($booking->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to complete booking',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reschedule a booking.
     */
    public function reschedule(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();

        // Check if user has access to this booking
        if ($booking->customer_id !== $user->id && $booking->vendor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'scheduled_at' => 'required|date|after:now',
        ]);

        try {
            $this->bookingService->rescheduleBooking($booking, $request->get('scheduled_at'));
            
            return response()->json([
                'message' => 'Booking rescheduled successfully',
                'booking' => new BookingResource($booking->fresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to reschedule booking',
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
