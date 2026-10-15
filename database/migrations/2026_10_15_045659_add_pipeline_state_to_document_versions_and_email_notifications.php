<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->char('preview_generation', 36)->nullable();
            $table->string('preview_source', 16)->nullable();
            $table->string('preview_error_code', 64)->nullable();
            $table->string('finalization_error_code', 64)->nullable();
            $table->index(['preview_status', 'status']);
            $table->index(['status', 'finalization_error_code']);
        });
        Schema::table('email_notifications', function (Blueprint $table): void {
            $table->string('last_error_code', 64)->nullable();
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropIndex(['preview_status', 'status']);
            $table->dropIndex(['status', 'finalization_error_code']);
            $table->dropColumn(['preview_generation', 'preview_source', 'preview_error_code', 'finalization_error_code']);
        });
        Schema::table('email_notifications', function (Blueprint $table): void {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn('last_error_code');
        });
    }
};
