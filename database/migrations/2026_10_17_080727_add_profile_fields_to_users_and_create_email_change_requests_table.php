<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_photo_path')->nullable();
            $table->text('google_photo_url')->nullable();
        });

        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('user_guid', 36)->unique();
            $table->string('new_email')->unique();
            $table->char('token_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->foreign('user_guid')->references('guid')->on('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_photo_path', 'google_photo_url']);
        });
    }
};
