<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_identities', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('user_guid', 36);
            $table->string('provider', 32);
            $table->string('provider_user_id');
            $table->foreign('user_guid')->references('guid')->on('users');
            $table->unique(['provider', 'provider_user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_identities');
    }
};
