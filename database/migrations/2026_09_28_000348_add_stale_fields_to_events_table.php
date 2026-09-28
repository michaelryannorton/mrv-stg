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
            // Subset of overridden_fields whose locked value has drifted from what the source now
            // says, discovered at ingestion time. A field only ever appears here while it's also
            // in overridden_fields — see IngestIcsSources::processSource() and Event::valuesDiffer().
            $table->json('stale_fields')->nullable()->after('overridden_fields');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('stale_fields');
        });
    }
};
