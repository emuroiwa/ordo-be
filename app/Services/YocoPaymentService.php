<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use Exception;

class YocoPaymentService
{
    private string $apiUrl;
    private string $secretKey;
    private string $publicKey;
    private string $webhookSecret;

    public function __construct()
    {
        $this->apiUrl = config('services.yoco.api_url', 'https://api.yoco.com/v1');
        $this->secretKey = config('services.yoco.secret_key');
        $this->publicKey = config('services.yoco.public_key');
        $this->webhookSecret = config('services.yoco.webhook_secret');

        if (!$this->secretKey) {
            throw new Exception('Yoco secret key is not configured');
        }
    }

    /**
     * Create a payment intent for a booking
     */
    public function createPaymentIntent(Booking $booking, array $options = []): array
    {
        try {
            // Calculate amounts
            $amount = $booking->total_amount * 100; // Convert to cents
            $platformFeePercentage = config('app.platform_fee_percentage', 5.0);
            $platformFee = ($booking->total_amount * $platformFeePercentage / 100) * 100; // In cents
            $vendorAmount = $amount - $platformFee;

            $payload = [
                'amount' => $amount,
                'currency' => 'ZAR',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'service_id' => $booking->service_id,
                    'customer_id' => $booking->customer_id,
                    'vendor_id' => $booking->vendor_id,
                    'platform_fee' => $platformFee,
                    'vendor_amount' => $vendorAmount,
                ],
                'receipt_email' => $booking->customer_email ?: $booking->customer?->email,
                'description' => "Booking for {$booking->service->title} - ORDO",
                'statement_descriptor' => 'ORDO Booking',
            ];

            // Add webhook URL if configured
            if (config('services.yoco.webhook_url')) {
                $payload['webhook_url'] = config('services.yoco.webhook_url');
            }

            // Merge any additional options
            $payload = array_merge($payload, $options);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/charges', $payload);

            if (!$response->successful()) {
                Log::error('Yoco payment intent creation failed', [
                    'booking_id' => $booking->id,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                
                throw new Exception('Failed to create payment intent: ' . $response->body());
            }

            $data = $response->json();
            
            // Create payment record
            $payment = $this->createPaymentRecord($booking, $data, $platformFee, $vendorAmount);
            
            return [
                'payment_intent' => $data,
                'payment' => $payment,
                'client_secret' => $data['id'], // Yoco uses charge ID as client secret
            ];

        } catch (RequestException $e) {
            Log::error('Yoco API request failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'response' => $e->response?->body(),
            ]);
            
            throw new Exception('Payment service unavailable. Please try again later.');
            
        } catch (Exception $e) {
            Log::error('Payment intent creation error', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Confirm a payment
     */
    public function confirmPayment(string $chargeId, array $paymentData = []): array
    {
        try {
            // Get charge details from Yoco
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->apiUrl . "/charges/{$chargeId}");

            if (!$response->successful()) {
                throw new Exception('Failed to retrieve charge details');
            }

            $chargeData = $response->json();
            
            // Find the payment record
            $payment = Payment::where('provider_payment_id', $chargeId)->first();
            
            if (!$payment) {
                throw new Exception('Payment record not found');
            }

            // Update payment status based on charge status
            $this->updatePaymentFromCharge($payment, $chargeData);

            return [
                'payment' => $payment->fresh(),
                'charge' => $chargeData,
            ];

        } catch (Exception $e) {
            Log::error('Payment confirmation error', [
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Process a webhook event
     */
    public function processWebhook(array $payload, string $signature): bool
    {
        try {
            // Verify webhook signature
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                Log::warning('Invalid webhook signature', ['payload' => $payload]);
                return false;
            }

            $eventType = $payload['type'] ?? null;
            $chargeData = $payload['data']['object'] ?? null;

            if (!$eventType || !$chargeData) {
                Log::warning('Invalid webhook payload', ['payload' => $payload]);
                return false;
            }

            Log::info('Processing Yoco webhook', [
                'event_type' => $eventType,
                'charge_id' => $chargeData['id'] ?? null,
            ]);

            // Find the payment record
            $payment = Payment::where('provider_payment_id', $chargeData['id'])->first();
            
            if (!$payment) {
                Log::warning('Payment not found for webhook', [
                    'charge_id' => $chargeData['id'],
                    'event_type' => $eventType,
                ]);
                return false;
            }

            // Process based on event type
            switch ($eventType) {
                case 'charge.succeeded':
                    $this->handleChargeSucceeded($payment, $chargeData);
                    break;
                    
                case 'charge.failed':
                    $this->handleChargeFailed($payment, $chargeData);
                    break;
                    
                case 'charge.pending':
                    $this->handleChargePending($payment, $chargeData);
                    break;
                    
                case 'refund.succeeded':
                    $this->handleRefundSucceeded($payment, $chargeData);
                    break;
                    
                default:
                    Log::info('Unhandled webhook event type', [
                        'event_type' => $eventType,
                        'charge_id' => $chargeData['id'],
                    ]);
            }

            return true;

        } catch (Exception $e) {
            Log::error('Webhook processing error', [
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Create a refund
     */
    public function createRefund(Payment $payment, float $amount = null, string $reason = null): array
    {
        try {
            $refundAmount = $amount ? ($amount * 100) : null; // Convert to cents
            
            $payload = array_filter([
                'charge' => $payment->provider_payment_id,
                'amount' => $refundAmount,
                'reason' => $reason ?: 'requested_by_customer',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'booking_id' => $payment->booking_id,
                    'refund_reason' => $reason,
                ],
            ]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
            ])->post($this->apiUrl . '/refunds', $payload);

            if (!$response->successful()) {
                Log::error('Yoco refund creation failed', [
                    'payment_id' => $payment->id,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                
                throw new Exception('Failed to create refund: ' . $response->body());
            }

            $refundData = $response->json();
            
            // Update payment record
            $payment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_amount' => ($refundData['amount'] ?? $payment->amount * 100) / 100,
                'refund_reason' => $reason,
                'provider_response' => array_merge(
                    $payment->provider_response ?? [],
                    ['refund' => $refundData]
                ),
            ]);

            return [
                'refund' => $refundData,
                'payment' => $payment->fresh(),
            ];

        } catch (Exception $e) {
            Log::error('Refund creation error', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Get payment details from Yoco
     */
    public function getPaymentDetails(string $chargeId): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
            ])->get($this->apiUrl . "/charges/{$chargeId}");

            if (!$response->successful()) {
                throw new Exception('Failed to retrieve payment details');
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('Get payment details error', [
                'charge_id' => $chargeId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Create payment record in database
     */
    private function createPaymentRecord(Booking $booking, array $chargeData, int $platformFee, int $vendorAmount): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'vendor_id' => $booking->vendor_id,
            'service_id' => $booking->service_id,
            'amount' => $booking->total_amount,
            'platform_fee' => $platformFee / 100, // Convert back to decimal
            'vendor_amount' => $vendorAmount / 100, // Convert back to decimal
            'currency' => 'ZAR',
            'status' => $this->mapYocoStatusToPaymentStatus($chargeData['status']),
            'payment_method' => 'card',
            'payment_provider' => 'yoco',
            'provider_payment_id' => $chargeData['id'],
            'provider_response' => $chargeData,
        ]);
    }

    /**
     * Update payment from charge data
     */
    private function updatePaymentFromCharge(Payment $payment, array $chargeData): void
    {
        $status = $this->mapYocoStatusToPaymentStatus($chargeData['status']);
        
        $updateData = [
            'status' => $status,
            'provider_response' => array_merge(
                $payment->provider_response ?? [],
                $chargeData
            ),
        ];

        if ($status === 'completed') {
            $updateData['processed_at'] = now();
        }

        $payment->update($updateData);

        // Update booking status if payment completed
        if ($status === 'completed' && $payment->booking) {
            $payment->booking->update(['payment_status' => 'paid']);
        }
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

    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(array $payload, string $signature): bool
    {
        if (!$this->webhookSecret) {
            Log::warning('Webhook secret not configured, skipping signature verification');
            return true; // Allow if not configured for testing
        }

        $expectedSignature = hash_hmac('sha256', json_encode($payload), $this->webhookSecret);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Handle successful charge webhook
     */
    private function handleChargeSucceeded(Payment $payment, array $chargeData): void
    {
        $payment->update([
            'status' => 'completed',
            'processed_at' => now(),
            'provider_response' => array_merge(
                $payment->provider_response ?? [],
                $chargeData
            ),
        ]);

        // Update booking status
        if ($payment->booking) {
            $payment->booking->update(['payment_status' => 'paid']);
        }

        Log::info('Payment completed successfully', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeData['id'],
        ]);
    }

    /**
     * Handle failed charge webhook
     */
    private function handleChargeFailed(Payment $payment, array $chargeData): void
    {
        $payment->update([
            'status' => 'failed',
            'provider_response' => array_merge(
                $payment->provider_response ?? [],
                $chargeData
            ),
        ]);

        // Update booking status
        if ($payment->booking) {
            $payment->booking->update(['payment_status' => 'failed']);
        }

        Log::warning('Payment failed', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeData['id'],
            'failure_reason' => $chargeData['failure_reason'] ?? 'Unknown',
        ]);
    }

    /**
     * Handle pending charge webhook
     */
    private function handleChargePending(Payment $payment, array $chargeData): void
    {
        $payment->update([
            'status' => 'processing',
            'provider_response' => array_merge(
                $payment->provider_response ?? [],
                $chargeData
            ),
        ]);

        Log::info('Payment processing', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeData['id'],
        ]);
    }

    /**
     * Handle successful refund webhook
     */
    private function handleRefundSucceeded(Payment $payment, array $chargeData): void
    {
        $payment->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'refund_amount' => ($chargeData['amount'] ?? $payment->amount * 100) / 100,
            'provider_response' => array_merge(
                $payment->provider_response ?? [],
                ['refund' => $chargeData]
            ),
        ]);

        Log::info('Refund completed successfully', [
            'payment_id' => $payment->id,
            'refund_amount' => $payment->refund_amount,
        ]);
    }
}