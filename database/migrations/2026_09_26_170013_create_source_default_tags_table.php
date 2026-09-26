<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_default_tags', function (Blueprint $table) {
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['source_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_default_tags');
    }
};
