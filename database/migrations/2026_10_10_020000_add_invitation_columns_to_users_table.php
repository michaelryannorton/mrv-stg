<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // invited_at is when the most recent invitation email was sent (resending updates it).
            // A user is pending while invited_at is set and invitation_accepted_at is null. The
            // owner account has neither and counts as active.
            $table->timestamp('invited_at')->nullable()->after('remember_token');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invited_at');
            $table->foreignId('invited_by_user_id')->nullable()->after('invitation_accepted_at')->constrained('users')->nullOnDelete();
        });

        // Same shape as password_reset_tokens. A separate table because that one holds one row per
        // email, so an invitation and a password reset would overwrite each other's token.
        Schema::create('invitation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by_user_id');
            $table->dropColumn(['invited_at', 'invitation_accepted_at']);
        });
    }
};
