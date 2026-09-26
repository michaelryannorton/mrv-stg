<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_records', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();

            $table->string('external_id', 500)->nullable();
            $table->text('source_url')->nullable();

            // event, content, venue, organization, unknown
            $table->string('record_type');

            $table->json('raw_payload')->nullable();
            $table->longText('raw_text')->nullable();

            $table->string('content_hash', 128)->nullable();

            $table->timestamp('observed_at');
            $table->timestamp('processed_at')->nullable();

            // new, processing, processed, failed, ignored, duplicate
            $table->string('processing_status')->default('new');
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['source_id', 'external_id']);
            $table->index('content_hash');
            $table->index('processing_status');
            $table->index('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_records');
    }
};
