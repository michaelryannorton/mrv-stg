<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (event, channel), so an event has exactly one explicit state in a channel:
        // curated (added by hand), featured (a highlight pick) or excluded (removed from the
        // channel although its categories would include it). Events that are in a channel only
        // through its linked categories have no row; see Event::scopeInChannel().
        Schema::create('event_channels', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('relationship_type', 20); // curated, featured, excluded
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['event_id', 'channel_id']);
            $table->index(['channel_id', 'relationship_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_channels');
    }
};
