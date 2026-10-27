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
        Schema::create('document_version_qrs', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('version_guid', 36)->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('encrypted_token');
            $table->timestamp('revoked_at')->nullable();
            $table->foreign('version_guid')->references('guid')->on('document_versions')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_version_qrs');
    }
};
