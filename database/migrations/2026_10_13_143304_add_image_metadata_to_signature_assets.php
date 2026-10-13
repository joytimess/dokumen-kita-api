<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_assets', function (Blueprint $table): void {
            $table->string('mime_type', 32)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signature_assets', function (Blueprint $table): void {
            $table->dropColumn(['mime_type', 'size_bytes', 'width', 'height']);
        });
    }
};
