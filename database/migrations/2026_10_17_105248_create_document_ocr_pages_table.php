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
        Schema::create('document_ocr_pages', function (Blueprint $table) {
            $table->id();
            $table->char('version_guid', 36);
            $table->char('pdf_hash', 64);
            $table->unsignedSmallInteger('page_number');
            $table->string('source', 16)->default('ocr');
            $table->string('status', 16);
            $table->longText('text')->nullable();
            $table->json('words')->nullable();
            $table->unsignedInteger('page_width')->nullable();
            $table->unsignedInteger('page_height')->nullable();
            $table->smallInteger('page_rotation')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->boolean('retryable')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->foreign('version_guid')->references('guid')->on('document_versions')->cascadeOnDelete();
            $table->unique(['version_guid', 'pdf_hash', 'page_number'], 'document_ocr_page_unique');
            $table->index(['version_guid', 'pdf_hash', 'status'], 'document_ocr_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_ocr_pages');
    }
};
