<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row for each street address sent to the geocoder, with its answer, so an address is asked
     * once and one that failed is not asked again on every run (Michael, 2026-11-27).
     */
    public function up(): void
    {
        Schema::create('place_lookups', function (Blueprint $table) {
            $table->id();
            // The address as compared: lower case, one space, "street|city|state|zip".
            $table->string('lookup_key', 400)->unique();
            $table->string('street');
            $table->string('city')->nullable();
            $table->string('state', 40)->nullable();
            $table->string('postal_code', 20)->nullable();
            // matched, not_matched, error
            $table->string('status', 20)->index();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('matched_address')->nullable();
            $table->string('provider', 40)->default('census');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('looked_up_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_lookups');
    }
};
