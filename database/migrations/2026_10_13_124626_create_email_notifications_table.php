<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_notifications', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('version_guid', 36);
            $table->char('assignment_guid', 36)->nullable();
            $table->string('recipient_email');
            $table->string('notification_type', 48);
            $table->string('deduplication_key')->unique();
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreign('version_guid')->references('guid')->on('document_versions');
            $table->foreign('assignment_guid')->references('guid')->on('signer_assignments');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notifications');
    }
};
