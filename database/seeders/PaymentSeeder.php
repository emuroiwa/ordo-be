<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Service;
use App\Models\Booking;
use Carbon\Carbon;

class PaymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get users and services for creating payments
        $customers = User::whereJsonContains('roles', 'customer')->take(10)->get();
        $vendors = User::whereJsonContains('roles', 'vendor')->take(5)->get();
        $services = Service::with('user')->take(20)->get();
        
        if ($customers->isEmpty() || $vendors->isEmpty() || $services->isEmpty()) {
            $this->command->info('No customers, vendors, or services found. Skipping payment seeding.');
            return;
        }

        // Create payment methods for customers
        foreach ($customers as $customer) {
            // Create 1-3 payment methods per customer
            $methodCount = rand(1, 3);
            
            for ($i = 0; $i < $methodCount; $i++) {
                $type = ['card', 'bank_account', 'wallet'][array_rand(['card', 'bank_account', 'wallet'])];
                $brands = ['visa', 'mastercard', 'amex', 'discover'];
                $banks = ['First National Bank', 'Standard Bank', 'ABSA', 'Nedbank', 'Capitec'];
                
                PaymentMethod::create([
                    'user_id' => $customer->id,
                    'type' => $type,
                    'provider' => $type === 'card' ? 'stripe' : null,
                    'provider_method_id' => 'pm_' . strtoupper(uniqid()),
                    'is_default' => $i === 0, // First method is default
                    'last_four' => str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT),
                    'brand' => $type === 'card' ? $brands[array_rand($brands)] : null,
                    'bank_name' => $type === 'bank_account' ? $banks[array_rand($banks)] : null,
                    'exp_month' => $type === 'card' ? rand(1, 12) : null,
                    'exp_year' => $type === 'card' ? rand(date('Y'), date('Y') + 5) : null,
                    'is_active' => true,
                    'verified_at' => now(),
                ]);
            }
        }

        // Create payments for the past 12 months
        foreach ($services as $service) {
            // Create 3-15 payments per service
            $paymentCount = rand(3, 15);
            
            for ($i = 0; $i < $paymentCount; $i++) {
                $customer = $customers->random();
                $vendor = $service->user;
                
                // Create payment with random date in the past 12 months
                $createdAt = Carbon::now()->subDays(rand(1, 365));
                $amount = rand(50, 500);
                $platformFee = $amount * 0.05; // 5% platform fee
                $vendorAmount = $amount - $platformFee;
                
                $statuses = ['completed', 'completed', 'completed', 'pending', 'processing'];
                $status = $statuses[array_rand($statuses)];
                $paymentMethods = ['card', 'bank_transfer', 'wallet'];
                $paymentMethod = $paymentMethods[array_rand($paymentMethods)];
                
                $payment = Payment::create([
                    'booking_id' => null, // We'll create bookings later if needed
                    'customer_id' => $customer->id,
                    'vendor_id' => $vendor->id,
                    'service_id' => $service->id,
                    'amount' => $amount,
                    'platform_fee' => $platformFee,
                    'vendor_amount' => $vendorAmount,
                    'currency' => 'ZAR',
                    'status' => $status,
                    'payment_method' => $paymentMethod,
                    'payment_provider' => $paymentMethod === 'card' ? 'stripe' : null,
                    'provider_payment_id' => 'pi_' . strtoupper(uniqid()),
                    'processed_at' => $status === 'completed' ? $createdAt->addMinutes(rand(5, 60)) : null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                // Create transaction records for completed payments
                if ($status === 'completed') {
                    // Customer payment transaction
                    Transaction::create([
                        'user_id' => $customer->id,
                        'payment_id' => $payment->id,
                        'type' => 'payment',
                        'amount' => -$amount, // Negative for customer (debit)
                        'currency' => 'ZAR',
                        'status' => 'completed',
                        'reference' => Transaction::generateReference(),
                        'description' => "Payment for {$service->title}",
                        'processed_at' => $payment->processed_at,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Vendor payment transaction
                    Transaction::create([
                        'user_id' => $vendor->id,
                        'payment_id' => $payment->id,
                        'type' => 'payment',
                        'amount' => $vendorAmount, // Positive for vendor (credit)
                        'currency' => 'ZAR',
                        'status' => 'completed',
                        'reference' => Transaction::generateReference(),
                        'description' => "Received payment for {$service->title}",
                        'processed_at' => $payment->processed_at,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Platform fee transaction
                    Transaction::create([
                        'user_id' => $vendor->id,
                        'payment_id' => $payment->id,
                        'type' => 'fee',
                        'amount' => -$platformFee, // Negative for vendor (debit)
                        'currency' => 'ZAR',
                        'status' => 'completed',
                        'reference' => Transaction::generateReference(),
                        'description' => "Platform fee for {$service->title}",
                        'processed_at' => $payment->processed_at,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }

                // Occasionally create refunds for completed payments
                if ($status === 'completed' && rand(0, 100) < 10) { // 10% chance of refund
                    $refundDate = $createdAt->copy()->addDays(rand(1, 30));
                    $refundAmount = rand(20, $amount); // Partial or full refund
                    
                    $payment->update([
                        'status' => 'refunded',
                        'refunded_at' => $refundDate,
                        'refund_amount' => $refundAmount,
                        'refund_reason' => 'Customer requested refund',
                    ]);

                    // Customer refund transaction
                    Transaction::create([
                        'user_id' => $customer->id,
                        'payment_id' => $payment->id,
                        'type' => 'refund',
                        'amount' => $refundAmount, // Positive for customer (credit)
                        'currency' => 'ZAR',
                        'status' => 'completed',
                        'reference' => Transaction::generateReference(),
                        'description' => "Refund for {$service->title}",
                        'processed_at' => $refundDate,
                        'created_at' => $refundDate,
                        'updated_at' => $refundDate,
                    ]);

                    // Vendor refund transaction
                    $vendorRefundAmount = $refundAmount * ($vendorAmount / $amount);
                    Transaction::create([
                        'user_id' => $vendor->id,
                        'payment_id' => $payment->id,
                        'type' => 'refund',
                        'amount' => -$vendorRefundAmount, // Negative for vendor (debit)
                        'currency' => 'ZAR',
                        'status' => 'completed',
                        'reference' => Transaction::generateReference(),
                        'description' => "Refund issued for {$service->title}",
                        'processed_at' => $refundDate,
                        'created_at' => $refundDate,
                        'updated_at' => $refundDate,
                    ]);
                }
            }
        }

        // Create some payout transactions for vendors
        foreach ($vendors as $vendor) {
            $payoutCount = rand(2, 6);
            
            for ($i = 0; $i < $payoutCount; $i++) {
                $payoutDate = Carbon::now()->subDays(rand(1, 365));
                $payoutAmount = rand(500, 5000);
                
                Transaction::create([
                    'user_id' => $vendor->id,
                    'type' => 'payout',
                    'amount' => -$payoutAmount, // Negative for vendor (debit)
                    'currency' => 'ZAR',
                    'status' => 'completed',
                    'reference' => Transaction::generateReference(),
                    'description' => 'Payout to bank account',
                    'processed_at' => $payoutDate,
                    'created_at' => $payoutDate,
                    'updated_at' => $payoutDate,
                ]);
            }
        }

        $this->command->info('Payments, transactions, and payment methods seeded successfully!');
    }
}
