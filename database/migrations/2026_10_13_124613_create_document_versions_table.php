<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('document_guid', 36);
            $table->unsignedInteger('version_number');
            $table->json('editor_data_json')->nullable();
            $table->string('source_type', 8)->nullable();
            $table->string('source_path')->nullable();
            $table->string('preview_pdf_path')->nullable();
            $table->string('preview_status', 16)->default('none');
            $table->string('final_pdf_path')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->char('manifest_hash', 64)->nullable();
            $table->char('final_pdf_hash', 64)->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreign('document_guid')->references('guid')->on('documents');
            $table->unique(['document_guid', 'version_number']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
