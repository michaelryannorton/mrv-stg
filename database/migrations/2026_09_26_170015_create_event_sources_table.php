<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('source_record_id')->nullable()->constrained('source_records')->nullOnDelete();

            $table->string('external_id', 500)->nullable();
            $table->text('source_url')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->boolean('is_primary')->default(false);
            $table->decimal('confidence_score', 5, 2)->nullable();

            $table->timestamps();

            // NULL external_id is allowed (webpages without a stable source identifier) — MySQL's
            // unique index treats each NULL as distinct, so this doesn't block multiple such rows.
            $table->unique(['source_id', 'external_id']);
            $table->index(['event_id', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sources');
    }
};
