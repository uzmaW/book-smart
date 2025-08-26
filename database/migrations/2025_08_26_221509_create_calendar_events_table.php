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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('external_id')->nullable(); // Google Calendar event ID or other external IDs
            $table->string('source')->default('local'); // local, google, ical, etc.
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('location')->nullable();
            $table->json('attendees')->nullable(); // Store attendees as JSON
            $table->enum('status', ['tentative', 'confirmed', 'cancelled'])->default('confirmed');
            $table->string('recurrence_rule')->nullable(); // RRULE for recurring events
            $table->json('reminders')->nullable(); // Store reminders as JSON
            $table->timestamps();
            
            $table->index(['user_id', 'start_time']);
            $table->index(['external_id', 'source']);
            $table->index(['status', 'start_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
