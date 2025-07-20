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
        Schema::create('booking_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignUuid('customer_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('vendor_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('service_id')->constrained('services')->onDelete('cascade');
            $table->integer('rating')->unsigned(); // 1-5 stars
            $table->text('review_text')->nullable();
            $table->boolean('is_published')->default(true);
            $table->json('review_metadata')->nullable(); // additional review data
            $table->timestamps();

            // Indexes
            $table->index(['vendor_id', 'is_published']);
            $table->index(['service_id', 'is_published']);
            $table->index(['customer_id']);
            $table->index('rating');
            $table->unique('booking_id'); // One review per booking
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_reviews');
    }
};
