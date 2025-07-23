<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('vendor_verification_id')->constrained()->onDelete('cascade');
            
            // Document details
            $table->enum('document_type', [
                'national_id',
                'passport',
                'drivers_license',
                'business_registration',
                'tax_certificate',
                'bank_statement',
                'proof_of_address',
                'professional_license',
                'other'
            ]);
            
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_hash')->nullable(); // For duplicate detection
            $table->integer('file_size');
            $table->string('mime_type');
            
            // Document processing status
            $table->enum('processing_status', [
                'uploaded',
                'processing',
                'processed',
                'verified',
                'rejected',
                'expired'
            ])->default('uploaded');
            
            // OCR and validation data
            $table->json('extracted_data')->nullable(); // OCR extracted information
            $table->json('validation_results')->nullable(); // Document authenticity checks
            $table->text('rejection_reason')->nullable();
            
            // Processing metadata
            $table->string('processor_service')->nullable(); // Which service processed it
            $table->string('processor_reference')->nullable(); // External service reference
            $table->timestamp('processed_at')->nullable();
            $table->uuid('processed_by')->nullable(); // Admin who reviewed
            
            // Security
            $table->boolean('is_sensitive')->default(true);
            $table->timestamp('expires_at')->nullable(); // When to delete document
            
            $table->timestamps();
            
            // Indexes with custom names to avoid MySQL length limit
            $table->index(['user_id', 'document_type'], 'vd_user_doc_type_idx');
            $table->index(['vendor_verification_id', 'processing_status'], 'vd_verification_status_idx');
            $table->index('processing_status', 'vd_processing_status_idx');
            $table->index('file_hash', 'vd_file_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_documents');
    }
};