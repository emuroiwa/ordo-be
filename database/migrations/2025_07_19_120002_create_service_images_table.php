<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained()->onDelete('cascade');
            
            // Original image metadata
            $table->string('original_filename');
            $table->string('original_path');
            $table->string('mime_type');
            $table->integer('file_size')->nullable(); // in bytes
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            
            // Optimized versions
            $table->json('thumbnails')->nullable(); // Different sizes and formats
            $table->string('cdn_url')->nullable(); // CDN URL for fast delivery
            $table->string('webp_path')->nullable(); // WebP version for modern browsers
            $table->string('avif_path')->nullable(); // AVIF for next-gen compression
            
            // Image metadata
            $table->string('alt_text')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            
            // Upload and processing status
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->json('processing_metadata')->nullable(); // Error details, etc.
            
            // Performance tracking
            $table->string('blurhash', 64)->nullable(); // For progressive loading
            $table->json('color_palette')->nullable(); // Dominant colors for UI
            
            $table->timestamps();

            $table->index(['service_id', 'sort_order']);
            $table->index(['service_id', 'is_primary']);
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_images');
    }
};