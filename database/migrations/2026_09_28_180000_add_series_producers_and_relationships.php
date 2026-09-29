<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_series', function (Blueprint $table) {
            $table->string('legacy_series_id')->nullable()->unique()->after('id');
            $table->string('cadence_raw')->nullable()->after('recurrence_rule');
            $table->string('event_type_raw')->nullable()->after('cadence_raw');
            $table->text('notes')->nullable()->after('event_type_raw');
        });

        Schema::create('event_series_producers', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_relation_id')->unique();
            $table->foreignId('event_series_id')->constrained('event_series')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            // primary, co_producer
            $table->string('role');
            $table->string('producer_raw')->nullable();
        });

        // Faithful copy of the research corpus's relationship graph — reference/provenance
        // data, not yet consumed by any live feature. from_type/to_type are one of
        // Entity/Source/Series/Place; a Place endpoint is unresolved free text (no local FK),
        // matching how the research corpus itself distinguishes a resolved canonical entity
        // from a literal place name it couldn't (or didn't need to) resolve further.
        Schema::create('relationships', function (Blueprint $table) {
            $table->id();
            $table->string('production_rel_id')->unique();
            $table->string('legacy_edge_id')->nullable();
            $table->string('from_type');
            $table->string('from_legacy_id')->nullable();
            $table->string('from_canonical_id')->nullable();
            $table->string('from_name')->nullable();
            $table->string('relationship_type');
            $table->string('to_type');
            $table->string('to_legacy_id')->nullable();
            $table->string('to_canonical_id')->nullable();
            $table->string('to_name')->nullable();
            $table->foreignId('evidence_source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('confidence')->nullable();
            $table->string('resolution_method')->nullable();
            $table->text('notes')->nullable();

            $table->index(['from_type', 'from_canonical_id']);
            $table->index(['to_type', 'to_canonical_id']);
            $table->index('relationship_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
        Schema::dropIfExists('event_series_producers');

        Schema::table('event_series', function (Blueprint $table) {
            $table->dropColumn(['legacy_series_id', 'cadence_raw', 'event_type_raw', 'notes']);
        });
    }
};
