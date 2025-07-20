<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('category_id')->constrained('service_categories');
            
            // Basic service info
            $table->string('title');
            $table->text('description');
            $table->text('short_description')->nullable(); // For cards/previews
            
            // Pricing
            $table->enum('price_type', ['fixed', 'hourly', 'negotiable'])->default('fixed');
            $table->decimal('base_price', 10, 2);
            $table->decimal('max_price', 10, 2)->nullable(); // For price ranges
            $table->string('currency', 3)->default('ZAR');
            
            // Service details
            $table->integer('duration_minutes')->nullable();
            $table->enum('location_type', ['client_location', 'service_location', 'online'])->default('client_location');
            $table->json('address')->nullable(); // Full address object
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            
            // Media and content
            $table->json('images')->nullable(); // Array of image objects with metadata
            $table->json('tags')->nullable(); // Searchable tags
            $table->json('requirements')->nullable(); // Service requirements/what customer needs to provide
            
            // Status and visibility
            $table->enum('status', ['draft', 'active', 'paused', 'archived'])->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->boolean('instant_booking')->default(false);
            
            // Analytics and metrics (denormalized for performance)
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->integer('review_count')->default(0);
            $table->integer('booking_count')->default(0);
            $table->integer('view_count')->default(0);
            $table->integer('favorite_count')->default(0);
            
            // SEO and discovery
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('slug')->unique();
            
            // Business logic
            $table->json('availability_schedule')->nullable(); // Weekly schedule
            $table->integer('advance_booking_days')->default(30); // How far in advance can book
            $table->integer('cancellation_hours')->default(24); // Cancellation policy
            
            $table->timestamps();
            $table->softDeletes(); // Soft delete for audit trail

            // Performance indexes
            $table->index(['status', 'is_featured', 'category_id']);
            $table->index(['user_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['average_rating', 'review_count']);
            $table->index(['created_at', 'status']);
            $table->index('slug');
            $table->index(['latitude', 'longitude']); // Regular index for location queries
            
            // Full-text search index
            $table->fullText(['title', 'description', 'short_description']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};