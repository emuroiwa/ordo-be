<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\YocoPaymentService;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class YocoPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private YocoPaymentService $yocoService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set test configuration
        Config::set('services.yoco.api_url', 'https://api.yoco.com/v1');
        Config::set('services.yoco.secret_key', 'sk_test_12345');
        Config::set('services.yoco.public_key', 'pk_test_12345');
        Config::set('services.yoco.webhook_secret', 'webhook_secret_12345');
        Config::set('app.platform_fee_percentage', 5.0);

        $this->yocoService = new YocoPaymentService();
    }

    public function test_constructor_throws_exception_without_secret_key()
    {
        Config::set('services.yoco.secret_key', null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Yoco secret key is not configured');

        new YocoPaymentService();
    }

    public function test_create_payment_intent_success()
    {
        // Create test data
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'base_price' => 150.00,
        ]);
        $booking = Booking::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'service_id' => $service->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'customer_email' => 'test@example.com',
            'total_amount' => 150.00,
            'duration_minutes' => 60,
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        // Mock Yoco API response
        Http::fake([
            'api.yoco.com/v1/charges' => Http::response([
                'id' => 'ch_test_12345',
                'amount' => 15000, // in cents
                'currency' => 'ZAR',
                'status' => 'pending',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'platform_fee' => 750,
                    'vendor_amount' => 14250,
                ],
            ], 200),
        ]);

        // Test payment intent creation
        $result = $this->yocoService->createPaymentIntent($booking);

        // Assertions
        $this->assertArrayHasKey('payment_intent', $result);
        $this->assertArrayHasKey('payment', $result);
        $this->assertArrayHasKey('client_secret', $result);

        $payment = $result['payment'];
        $this->assertEquals($booking->id, $payment->booking_id);
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals(150.00, $payment->amount);
        $this->assertEquals(7.50, $payment->platform_fee);
        $this->assertEquals(142.50, $payment->vendor_amount);
        $this->assertEquals('yoco', $payment->payment_provider);
    }

    public function test_create_payment_intent_api_failure()
    {
        $user = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $user->id]);
        $booking = Booking::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'service_id' => $service->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'customer_email' => 'test@example.com',
            'total_amount' => 150.00,
            'duration_minutes' => 60,
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        // Mock API failure
        Http::fake([
            'api.yoco.com/v1/charges' => Http::response([
                'error' => 'Invalid API key',
            ], 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to create payment intent');

        $this->yocoService->createPaymentIntent($booking);
    }

    public function test_confirm_payment_success()
    {
        // Create test payment
        $user = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $user->id]);
        $booking = Booking::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'service_id' => $service->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'total_amount' => 150.00,
            'duration_minutes' => 60,
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $payment = Payment::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'booking_id' => $booking->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'service_id' => $service->id,
            'amount' => 150.00,
            'platform_fee' => 7.50,
            'vendor_amount' => 142.50,
            'currency' => 'ZAR',
            'status' => 'pending',
            'payment_method' => 'card',
            'payment_provider' => 'yoco',
            'provider_payment_id' => 'ch_test_12345',
        ]);

        // Mock Yoco API response
        Http::fake([
            'api.yoco.com/v1/charges/ch_test_12345' => Http::response([
                'id' => 'ch_test_12345',
                'amount' => 15000,
                'currency' => 'ZAR',
                'status' => 'successful',
            ], 200),
        ]);

        $result = $this->yocoService->confirmPayment('ch_test_12345');

        // Assertions
        $this->assertArrayHasKey('payment', $result);
        $this->assertArrayHasKey('charge', $result);

        $payment = $result['payment'];
        $this->assertEquals('completed', $payment->status);
        $this->assertNotNull($payment->processed_at);
    }

    public function test_webhook_signature_verification()
    {
        $payload = [
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'id' => 'ch_test_12345',
                    'status' => 'successful',
                ],
            ],
        ];

        // Create test payment for webhook
        $user = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $user->id]);
        $booking = Booking::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'service_id' => $service->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'total_amount' => 150.00,
            'duration_minutes' => 60,
            'scheduled_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        Payment::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'booking_id' => $booking->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'service_id' => $service->id,
            'amount' => 150.00,
            'platform_fee' => 7.50,
            'vendor_amount' => 142.50,
            'currency' => 'ZAR',
            'status' => 'processing',
            'payment_method' => 'card',
            'payment_provider' => 'yoco',
            'provider_payment_id' => 'ch_test_12345',
        ]);

        // Test with valid signature
        $validSignature = hash_hmac('sha256', json_encode($payload), 'webhook_secret_12345');
        $result = $this->yocoService->processWebhook($payload, $validSignature);
        $this->assertTrue($result);

        // Test with invalid signature
        $invalidSignature = 'invalid_signature';
        $result = $this->yocoService->processWebhook($payload, $invalidSignature);
        $this->assertFalse($result);
    }

    public function test_refund_creation_success()
    {
        // Create completed payment
        $user = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $user->id]);
        $booking = Booking::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'service_id' => $service->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'total_amount' => 150.00,
            'duration_minutes' => 60,
            'scheduled_at' => now()->addDay(),
            'status' => 'confirmed',
        ]);

        $payment = Payment::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'booking_id' => $booking->id,
            'customer_id' => $user->id,
            'vendor_id' => $service->user_id,
            'service_id' => $service->id,
            'amount' => 150.00,
            'platform_fee' => 7.50,
            'vendor_amount' => 142.50,
            'currency' => 'ZAR',
            'status' => 'completed',
            'payment_method' => 'card',
            'payment_provider' => 'yoco',
            'provider_payment_id' => 'ch_test_12345',
            'processed_at' => now()->subHour(),
        ]);

        // Mock Yoco refund API
        Http::fake([
            'api.yoco.com/v1/refunds' => Http::response([
                'id' => 'rf_test_12345',
                'charge' => 'ch_test_12345',
                'amount' => 7500, // Partial refund in cents
                'currency' => 'ZAR',
                'status' => 'succeeded',
            ], 200),
        ]);

        $result = $this->yocoService->createRefund($payment, 75.00, 'Customer requested');

        // Assertions
        $this->assertArrayHasKey('refund', $result);
        $this->assertArrayHasKey('payment', $result);

        $payment = $result['payment'];
        $this->assertEquals('refunded', $payment->status);
        $this->assertEquals(75.00, $payment->refund_amount);
        $this->assertEquals('Customer requested', $payment->refund_reason);
        $this->assertNotNull($payment->refunded_at);
    }

    public function test_status_mapping()
    {
        $reflection = new \ReflectionClass($this->yocoService);
        $method = $reflection->getMethod('mapYocoStatusToPaymentStatus');
        $method->setAccessible(true);

        // Test status mappings
        $this->assertEquals('completed', $method->invoke($this->yocoService, 'successful'));
        $this->assertEquals('processing', $method->invoke($this->yocoService, 'pending'));
        $this->assertEquals('failed', $method->invoke($this->yocoService, 'failed'));
        $this->assertEquals('refunded', $method->invoke($this->yocoService, 'refunded'));
        $this->assertEquals('pending', $method->invoke($this->yocoService, 'unknown_status'));
    }
}