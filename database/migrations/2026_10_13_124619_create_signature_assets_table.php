<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_assets', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('user_guid', 36);
            $table->string('file_path');
            $table->char('file_hash', 64);
            $table->string('source_type', 16);
            $table->foreign('user_guid')->references('guid')->on('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_assets');
    }
};
