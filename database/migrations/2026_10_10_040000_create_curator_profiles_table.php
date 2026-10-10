<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The curator's public profile (Phase 2 slice 1c-iii): shown on a channel's landing page
        // once those exist (slice 1d), and only when is_public is set. The blurb is website-only;
        // the per-edition newsletter introduction is a separate field on the edition, later.
        Schema::create('curator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('display_name', 100);
            $table->string('blurb', 300)->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curator_profiles');
    }
};
