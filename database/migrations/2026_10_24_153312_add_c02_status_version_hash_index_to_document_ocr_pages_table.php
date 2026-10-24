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
        Schema::table('document_ocr_pages', function (Blueprint $table) {
            $table->index(['status', 'version_guid', 'pdf_hash'], 'document_ocr_pages_status_version_hash_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_ocr_pages', function (Blueprint $table) {
            $table->dropIndex('document_ocr_pages_status_version_hash_index');
        });
    }
};
