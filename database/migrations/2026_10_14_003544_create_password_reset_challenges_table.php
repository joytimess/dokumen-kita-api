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
        Schema::create('password_reset_challenges', function (Blueprint $table) {
            $table->char('user_guid', 36)->primary();
            $table->foreign('user_guid')->references('guid')->on('users')->cascadeOnDelete();
            $table->uuid('guid')->unique();
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('last_sent_at');
            $table->char('reset_token_hash', 64)->nullable();
            $table->timestamp('reset_token_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_challenges');
    }
};
