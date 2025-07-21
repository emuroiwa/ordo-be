<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->uuid('customer_id');
            $table->uuid('vendor_id');
            $table->uuid('service_id');
            $table->decimal('amount', 10, 2);
            $table->decimal('platform_fee', 10, 2)->default(0);
            $table->decimal('vendor_amount', 10, 2);
            $table->string('currency', 3)->default('ZAR');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'refunded', 'disputed'])->default('pending');
            $table->enum('payment_method', ['card', 'bank_transfer', 'wallet', 'cash']);
            $table->string('payment_provider')->nullable(); // stripe, paypal, etc.
            $table->string('provider_payment_id')->nullable();
            $table->string('provider_customer_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->text('refund_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');

            // Indexes
            $table->index(['customer_id', 'created_at']);
            $table->index(['vendor_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('provider_payment_id');
            $table->index('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
