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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // e.g., 'booking_confirmed', 'payment_received'
            $table->morphs('notifiable'); // user_id and user_type (already includes index)
            $table->json('data'); // notification content and metadata
            $table->timestamp('read_at')->nullable();
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->string('channel')->default('database'); // database, email, sms, push
            $table->json('metadata')->nullable(); // additional data like action_url, icon, etc.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index(['read_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
