<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Raw "Technology / Clues" text from the research corpus (e.g. "The Events Calendar /
            // WordPress" or "TeamSideline"). This is the actual platform signal used to group
            // sources into shared collectors — source_type is only a coarse ics/api/html_scraper
            // guess and isn't discriminating enough on its own.
            $table->string('technology_clues')->nullable()->after('source_class');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('technology_clues');
        });
    }
};
