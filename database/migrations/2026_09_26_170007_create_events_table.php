<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('event_series_id')->nullable()->constrained('event_series')->nullOnDelete();

            $table->string('title', 500);
            $table->string('slug', 500)->unique();

            $table->text('short_description')->nullable();
            $table->text('description')->nullable();

            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->string('timezone')->default('America/Los_Angeles');
            $table->boolean('all_day')->default(false);

            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('location_name_override')->nullable();
            $table->text('address_override')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();

            $table->text('canonical_url')->nullable();
            $table->text('ticket_url')->nullable();

            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('is_free')->nullable();

            $table->string('age_restriction')->nullable();
            $table->text('accessibility_notes')->nullable();

            $table->string('primary_image_path')->nullable();

            // status: draft, candidate, scheduled, cancelled, postponed, rescheduled, sold_out, completed, archived
            $table->string('status')->default('candidate');
            // editorial_status: unreviewed, pending_review, approved, rejected, published
            $table->string('editorial_status')->default('unreviewed');
            // verification_status: unverified, partially_verified, verified, stale
            $table->string('verification_status')->default('unverified');

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('start_at');
            $table->index('status');
            $table->index('editorial_status');
            $table->index(['editorial_status', 'start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
