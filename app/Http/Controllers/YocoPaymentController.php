<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\YocoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class YocoPaymentController extends Controller
{
    private YocoPaymentService $yocoService;

    public function __construct(YocoPaymentService $yocoService)
    {
        $this->yocoService = $yocoService;
    }

    /**
     * Create a payment intent for a booking
     */
    public function createPaymentIntent(Request $request, Booking $booking): JsonResponse
    {
        $request->validate([
            'save_payment_method' => 'sometimes|boolean',
            'customer_details' => 'sometimes|array',
            'customer_details.email' => 'sometimes|email',
            'customer_details.phone' => 'sometimes|string',
        ]);

        try {
            // Check authorization
            $user = auth()->user();
            if ($booking->customer_id && $booking->customer_id !== $user?->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to process payment for this booking'
                ], 403);
            }

            // Check if booking already has a successful payment
            if ($booking->payments()->completed()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking has already been paid for'
                ], 400);
            }

            // Create payment intent
            $options = [];
            if ($request->has('customer_details')) {
                $options['receipt_email'] = $request->input('customer_details.email');
            }

            $result = $this->yocoService->createPaymentIntent($booking, $options);

            return response()->json([
                'success' => true,
                'data' => [
                    'client_secret' => $result['client_secret'],
                    'payment_id' => $result['payment']->id,
                    'amount' => $booking->total_amount,
                    'currency' => 'ZAR',
                    'public_key' => config('services.yoco.public_key'),
                ],
                'message' => 'Payment intent created successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Payment intent creation failed', [
                'booking_id' => $booking->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent',
                'error' => config('app.debug') ? $e->getMessage() : 'Payment processing error'
            ], 500);
        }
    }

    /**
     * Confirm a payment
     */
    public function confirmPayment(Request $request): JsonResponse
    {
        $request->validate([
            'charge_id' => 'required|string',
            'payment_id' => 'required|exists:payments,id',
        ]);

        try {
            $payment = Payment::findOrFail($request->payment_id);
            
            // Check authorization
            $user = auth()->user();
            if ($payment->customer_id && $payment->customer_id !== $user?->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to confirm this payment'
                ], 403);
            }

            $result = $this->yocoService->confirmPayment($request->charge_id);
            $payment = $result['payment'];

            // Send response based on payment status
            if ($payment->status === 'completed') {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'payment' => $payment,
                        'booking' => $payment->booking,
                        'status' => 'completed',
                    ],
                    'message' => 'Payment completed successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'data' => [
                        'payment' => $payment,
                        'status' => $payment->status,
                    ],
                    'message' => "Payment {$payment->status}"
                ], $payment->status === 'failed' ? 400 : 202);
            }

        } catch (Exception $e) {
            Log::error('Payment confirmation failed', [
                'charge_id' => $request->charge_id,
                'payment_id' => $request->payment_id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment',
                'error' => config('app.debug') ? $e->getMessage() : 'Payment confirmation error'
            ], 500);
        }
    }

    /**
     * Handle Yoco webhooks
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $signature = $request->header('X-Yoco-Signature');
            $payload = $request->all();

            if (!$signature) {
                Log::warning('Webhook received without signature');
                return response()->json(['error' => 'Missing signature'], 400);
            }

            $processed = $this->yocoService->processWebhook($payload, $signature);

            if ($processed) {
                return response()->json(['status' => 'success']);
            } else {
                return response()->json(['error' => 'Webhook processing failed'], 400);
            }

        } catch (Exception $e) {
            Log::error('Webhook handling error', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Create a refund
     */
    public function createRefund(Request $request, Payment $payment): JsonResponse
    {
        $request->validate([
            'amount' => 'sometimes|numeric|min:0.01|max:' . $payment->amount,
            'reason' => 'sometimes|string|max:255',
        ]);

        try {
            // Check authorization (vendor or admin can refund)
            $user = auth()->user();
            $canRefund = $payment->vendor_id === $user?->id || 
                        in_array('admin', $user?->roles ?? []);
                        
            if (!$canRefund) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to refund this payment'
                ], 403);
            }

            // Check if payment can be refunded
            if (!$payment->is_refundable) {
                return response()->json([
                    'success' => false,
                    'message' => 'This payment cannot be refunded'
                ], 400);
            }

            $amount = $request->input('amount');
            $reason = $request->input('reason', 'Refunded by vendor');

            $result = $this->yocoService->createRefund($payment, $amount, $reason);

            return response()->json([
                'success' => true,
                'data' => [
                    'refund' => $result['refund'],
                    'payment' => $result['payment'],
                ],
                'message' => 'Refund created successfully'
            ]);

        } catch (Exception $e) {
            Log::error('Refund creation failed', [
                'payment_id' => $payment->id,
                'user_id' => auth()->id(),
                'amount' => $request->input('amount'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create refund',
                'error' => config('app.debug') ? $e->getMessage() : 'Refund processing error'
            ], 500);
        }
    }

    /**
     * Get payment status
     */
    public function getPaymentStatus(Payment $payment): JsonResponse
    {
        try {
            // Check authorization
            $user = auth()->user();
            $canAccess = $payment->customer_id === $user?->id || 
                        $payment->vendor_id === $user?->id ||
                        in_array('admin', $user?->roles ?? []);
                        
            if (!$canAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to access this payment'
                ], 403);
            }

            // Optionally refresh from Yoco if payment is not completed
            if (in_array($payment->status, ['pending', 'processing']) && $payment->provider_payment_id) {
                try {
                    $chargeDetails = $this->yocoService->getPaymentDetails($payment->provider_payment_id);
                    
                    // Update payment status based on latest charge data
                    $newStatus = $this->mapYocoStatusToPaymentStatus($chargeDetails['status']);
                    if ($newStatus !== $payment->status) {
                        $payment->update([
                            'status' => $newStatus,
                            'provider_response' => array_merge(
                                $payment->provider_response ?? [],
                                $chargeDetails
                            ),
                        ]);
                        
                        if ($newStatus === 'completed') {
                            $payment->update(['processed_at' => now()]);
                            $payment->booking?->update(['payment_status' => 'paid']);
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to refresh payment status from Yoco', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment' => $payment->fresh(),
                    'booking' => $payment->booking,
                ],
            ]);

        } catch (Exception $e) {
            Log::error('Get payment status error', [
                'payment_id' => $payment->id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get payment status',
                'error' => config('app.debug') ? $e->getMessage() : 'Status retrieval error'
            ], 500);
        }
    }

    /**
     * Get Yoco public key for frontend
     */
    public function getPublicKey(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'public_key' => config('services.yoco.public_key'),
                'currency' => 'ZAR',
            ],
        ]);
    }

    /**
     * Map Yoco status to our payment status
     */
    private function mapYocoStatusToPaymentStatus(string $yocoStatus): string
    {
        return match ($yocoStatus) {
            'successful' => 'completed',
            'pending' => 'processing',
            'failed' => 'failed',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }
}