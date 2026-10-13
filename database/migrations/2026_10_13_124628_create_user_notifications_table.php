<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('user_guid', 36);
            $table->char('document_guid', 36)->nullable();
            $table->char('version_guid', 36)->nullable();
            $table->char('assignment_guid', 36)->nullable();
            $table->string('event_type', 48);
            $table->json('payload_json')->nullable();
            $table->string('deduplication_key');
            $table->timestamp('read_at')->nullable();
            $table->foreign('user_guid')->references('guid')->on('users');
            $table->foreign('document_guid')->references('guid')->on('documents');
            $table->foreign('version_guid')->references('guid')->on('document_versions');
            $table->foreign('assignment_guid')->references('guid')->on('signer_assignments');
            $table->unique(['user_guid', 'deduplication_key']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
