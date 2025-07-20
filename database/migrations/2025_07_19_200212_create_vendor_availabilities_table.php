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
        Schema::create('vendor_availabilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('vendor_id');
            $table->enum('day_of_week', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
            $table->time('start_time');
            $table->time('end_time');
            $table->json('break_times')->nullable(); // Array of break periods
            $table->integer('default_duration')->default(60); // Default appointment duration in minutes
            $table->integer('buffer_time')->default(15); // Buffer time between appointments in minutes
            $table->date('effective_from')->nullable(); // When this availability starts
            $table->date('effective_until')->nullable(); // When this availability ends (null for ongoing)
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure vendor can't have overlapping availability for the same day
            $table->unique(['vendor_id', 'day_of_week', 'effective_from', 'effective_until'], 'vendor_availability_unique');
            $table->index(['vendor_id', 'day_of_week', 'is_active']);
            
            // Note: Foreign key constraint will be added later if needed
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_availabilities');
    }
};
