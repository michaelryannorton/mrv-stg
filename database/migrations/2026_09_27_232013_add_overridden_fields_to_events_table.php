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
        Schema::table('events', function (Blueprint $table) {
            // Names of columns that a manual edit has locked against being overwritten by the next
            // scheduled re-ingestion. Only the fields IngestIcsSources actually rewrites on update
            // need this; fields ingestion never touches are always safe to edit. See Event::INGESTED_FIELDS.
            $table->json('overridden_fields')->nullable()->after('verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('overridden_fields');
        });
    }
};
