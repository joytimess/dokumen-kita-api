<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->unsignedInteger('preview_page_count')->nullable();
        });
        Schema::table('email_notifications', function (Blueprint $table): void {
            $table->text('payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_notifications', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
        Schema::table('document_versions', function (Blueprint $table): void {
            $table->dropColumn('preview_page_count');
        });
    }
};
