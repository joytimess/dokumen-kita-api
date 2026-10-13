<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('owner_guid', 36);
            $table->string('public_reference', 64)->unique();
            $table->string('title');
            $table->string('status', 32)->default('draft');
            $table->foreign('owner_guid')->references('guid')->on('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
