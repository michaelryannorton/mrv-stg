<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default categories applied automatically to every event ingested from a source, per
        // community/Community Events Calendar - Phase 1 Plan.md section 3: categorize at the source
        // level first, refine to per-event overrides once patterns are understood.
        Schema::create('source_default_categories', function (Blueprint $table) {
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['source_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_default_categories');
    }
};
