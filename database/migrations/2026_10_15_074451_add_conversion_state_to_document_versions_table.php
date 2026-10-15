<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->string('conversion_status', 16)->default('none');
            $table->char('conversion_generation', 36)->nullable();
            $table->string('conversion_error_code', 64)->nullable();
            $table->json('conversion_warnings')->nullable();
            $table->unsignedInteger('conversion_page_count')->nullable();
            $table->unsignedInteger('editor_revision')->default(1);
            $table->index(['conversion_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropIndex(['conversion_status', 'status']);
            $table->dropColumn([
                'conversion_status', 'conversion_generation', 'conversion_error_code',
                'conversion_warnings', 'conversion_page_count', 'editor_revision',
            ]);
        });
    }
};
