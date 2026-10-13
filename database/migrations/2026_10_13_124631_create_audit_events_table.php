<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('document_guid', 36);
            $table->char('version_guid', 36)->nullable();
            $table->char('actor_guid', 36)->nullable();
            $table->string('action', 64);
            $table->json('metadata_json')->nullable();
            $table->foreign('document_guid')->references('guid')->on('documents');
            $table->foreign('version_guid')->references('guid')->on('document_versions');
            $table->foreign('actor_guid')->references('guid')->on('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
