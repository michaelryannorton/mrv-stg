<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_audiences', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('audience_id')->constrained('audiences')->cascadeOnDelete();
            $table->primary(['event_id', 'audience_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_audiences');
    }
};
