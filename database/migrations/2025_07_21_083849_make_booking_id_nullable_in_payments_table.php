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
        Schema::table('payments', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['booking_id']);
            
            // Modify the column to be nullable
            $table->uuid('booking_id')->nullable()->change();
            
            // Re-add the foreign key constraint
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['booking_id']);
            
            // Make the column not nullable again
            $table->uuid('booking_id')->nullable(false)->change();
            
            // Re-add the foreign key constraint
            $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
        });
    }
};