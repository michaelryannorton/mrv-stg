<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('legacy_entity_id')->nullable()->unique()->after('id');
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->string('legacy_entity_id')->nullable()->unique()->after('id');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('legacy_source_id')->nullable()->unique()->after('id');
            $table->foreignId('venue_id')->nullable()->after('organization_id')->constrained('venues')->nullOnDelete();
            // Editorial/domain classification (government_calendar, faith_calendar, venue_page, ...) —
            // separate axis from source_type, which describes the ingestion mechanism.
            $table->string('source_class')->nullable()->after('source_type');
            $table->string('access_scope')->default('unknown')->after('geographic_scope');
            $table->unsignedTinyInteger('discovery_value')->nullable()->after('access_scope');
            $table->unsignedTinyInteger('canonical_reliability')->nullable()->after('discovery_value');
            $table->unsignedTinyInteger('ingestion_friendliness')->nullable()->after('canonical_reliability');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_id');
            $table->dropColumn([
                'legacy_source_id', 'source_class', 'access_scope',
                'discovery_value', 'canonical_reliability', 'ingestion_friendliness',
            ]);
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn('legacy_entity_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('legacy_entity_id');
        });
    }
};
