<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            
            // Verification status
            $table->enum('status', [
                'pending',
                'email_verified',
                'documents_uploaded',
                'identity_verified',
                'liveness_verified',
                'business_verified',
                'approved',
                'rejected',
                'suspended'
            ])->default('pending');
            
            // Email verification
            $table->timestamp('email_verified_at')->nullable();
            $table->string('email_verification_token')->nullable();
            
            // Identity verification
            $table->json('identity_documents')->nullable(); // Array of uploaded documents
            $table->timestamp('identity_verified_at')->nullable();
            $table->json('identity_verification_data')->nullable(); // OCR extracted data
            $table->string('identity_verification_reference')->nullable();
            
            // Liveness verification
            $table->string('liveness_photo_path')->nullable();
            $table->timestamp('liveness_verified_at')->nullable();
            $table->json('liveness_verification_data')->nullable(); // Face matching scores, etc.
            $table->string('liveness_verification_reference')->nullable();
            
            // Business verification
            $table->string('business_registration_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            $table->json('business_address')->nullable();
            $table->json('business_documents')->nullable(); // Registration cert, tax cert, etc.
            $table->timestamp('business_verified_at')->nullable();
            
            // Verification metadata
            $table->json('verification_notes')->nullable(); // Admin notes
            $table->json('rejection_reasons')->nullable(); // If rejected
            $table->uuid('verified_by')->nullable(); // Admin who approved/rejected
            $table->timestamp('verified_at')->nullable(); // Final approval timestamp
            
            // Third-party service references
            $table->string('identity_service_provider')->nullable(); // e.g., 'smile_identity', 'jumio'
            $table->string('liveness_service_provider')->nullable();
            
            // Retry tracking
            $table->integer('verification_attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['user_id', 'status']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_verifications');
    }
};