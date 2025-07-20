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
        Schema::table('bookings', function (Blueprint $table) {
            // Guest booking fields - used when customer_id is null
            $table->string('guest_email')->nullable()->after('customer_id');
            $table->string('guest_phone')->nullable()->after('guest_email');
            $table->string('guest_name')->nullable()->after('guest_phone');
            
            // Make customer_id nullable to allow guest bookings
            $table->uuid('customer_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['guest_email', 'guest_phone', 'guest_name']);
            
            // Restore customer_id as required (note: this might fail if there are guest bookings)
            $table->uuid('customer_id')->nullable(false)->change();
        });
    }
};