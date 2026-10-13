<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signer_assignments', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('version_guid', 36);
            $table->char('user_guid', 36)->nullable();
            $table->string('recipient_email');
            $table->string('normalized_recipient_email');
            $table->unsignedInteger('sequence_number');
            $table->string('status', 32)->default('waiting');
            $table->timestamp('claimed_at')->nullable();
            $table->foreign('version_guid')->references('guid')->on('document_versions');
            $table->foreign('user_guid')->references('guid')->on('users');
            $table->unique(['version_guid', 'sequence_number']);
            $table->unique(['version_guid', 'normalized_recipient_email'], 'signer_assignments_version_email_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signer_assignments');
    }
};
