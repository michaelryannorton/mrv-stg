<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('name');
            // api, rss, ics, google_calendar, structured_html, html_scraper, manual, curator, public_submission
            $table->string('source_type');

            $table->text('base_url')->nullable();
            $table->text('feed_url')->nullable();

            $table->string('geographic_scope')->nullable();

            $table->string('collector_type');
            $table->json('collector_config')->nullable();

            $table->unsignedInteger('poll_interval_minutes')->nullable();

            $table->boolean('active')->default(true);
            // unknown, review_required, trusted, auto_publish
            $table->string('trust_level')->default('review_required');

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();

            $table->decimal('reliability_score', 5, 2)->nullable();

            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
