<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curator_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // lead, curator, contributor. Orders the public byline only; what a person may do
            // comes from their global role (see community/Community Phase 2 Slice 1 Plan.md, section 4).
            $table->string('role', 20)->default('curator');
            $table->string('status', 20)->default('active'); // active, ended
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curator_assignments');
    }
};
