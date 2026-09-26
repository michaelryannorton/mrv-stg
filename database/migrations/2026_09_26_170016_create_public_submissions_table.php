<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A holding table, deliberately separate from `events`: anonymous/unverified public input
        // should never touch the canonical table before someone has looked at it. See
        // community/Community Events Calendar - Phase 1 Plan.md section 3.
        Schema::create('public_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->string('submission_type')->default('event');

            // Optional, invited: "especially if you'd like to help curate this list."
            $table->string('email')->nullable();
            $table->string('name')->nullable();

            $table->text('source_url')->nullable();

            $table->json('submitted_data');

            // pending, approved, rejected, needs_information, spam
            $table->string('status')->default('pending');

            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_submissions');
    }
};
