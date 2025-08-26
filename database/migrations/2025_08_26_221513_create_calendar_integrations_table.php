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
        Schema::create('calendar_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['google', 'outlook', 'ical', 'caldav'])->default('google');
            $table->string('name'); // User-friendly name for the integration
            $table->string('external_calendar_id')->nullable(); // External calendar ID
            $table->json('credentials')->nullable(); // Encrypted credentials/tokens
            $table->json('settings')->nullable(); // Integration-specific settings
            $table->boolean('is_active')->default(true);
            $table->boolean('sync_enabled')->default(true);
            $table->enum('sync_direction', ['import', 'export', 'bidirectional'])->default('bidirectional');
            $table->timestamp('last_sync_at')->nullable();
            $table->json('sync_status')->nullable(); // Last sync status and errors
            $table->timestamps();
            
            $table->index(['user_id', 'type']);
            $table->index(['is_active', 'sync_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_integrations');
    }
};
