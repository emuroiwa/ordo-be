<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Vendor verification status
            $table->enum('vendor_verification_status', [
                'unverified',
                'pending',
                'in_progress', 
                'approved',
                'rejected',
                'suspended'
            ])->default('unverified')->after('roles');
            
            $table->timestamp('vendor_verified_at')->nullable()->after('vendor_verification_status');
            
            // Additional business fields
            $table->string('business_registration_number')->nullable()->after('service_category');
            $table->string('tax_identification_number')->nullable()->after('business_registration_number');
            $table->json('business_address')->nullable()->after('tax_identification_number');
            $table->text('business_description')->nullable()->after('business_address');
            
            // Quick verification status flags
            $table->boolean('email_verified')->default(false)->after('email_verified_at');
            $table->boolean('identity_verified')->default(false)->after('email_verified');
            $table->boolean('liveness_verified')->default(false)->after('identity_verified');
            $table->boolean('business_verified')->default(false)->after('liveness_verified');
            
            // Verification reminder tracking
            $table->timestamp('verification_reminder_sent_at')->nullable()->after('vendor_verified_at');
            $table->integer('verification_reminder_count')->default(0)->after('verification_reminder_sent_at');
            
            // Add indexes for performance with custom names
            $table->index('vendor_verification_status', 'users_vendor_status_idx');
            $table->index(['vendor_verification_status', 'created_at'], 'users_vendor_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_verification_status',
                'vendor_verified_at',
                'business_registration_number',
                'tax_identification_number', 
                'business_address',
                'business_description',
                'email_verified',
                'identity_verified',
                'liveness_verified',
                'business_verified',
                'verification_reminder_sent_at',
                'verification_reminder_count'
            ]);
        });
    }
};