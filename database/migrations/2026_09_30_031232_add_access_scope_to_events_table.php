<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Mirrors sources.access_scope (unknown/public/mixed/public_with_eligibility/
            // public_with_registration) — a source's classification alone doesn't tell us whether
            // any given event drawn from a "mixed" source is itself public, so this is set per
            // event at ingestion time from Source::$access_scope. See logs/2026 0929 2130 Event-Level
            // Access-Scope Classification.md.
            $table->string('access_scope')->default('unknown')->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('access_scope');
        });
    }
};
