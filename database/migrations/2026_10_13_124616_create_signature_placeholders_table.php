<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_placeholders', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('assignment_guid', 36);
            $table->unsignedInteger('page_number');
            $table->decimal('x', 8, 6);
            $table->decimal('y', 8, 6);
            $table->decimal('width', 8, 6);
            $table->decimal('height', 8, 6);
            $table->boolean('needs_review')->default(false);
            $table->foreign('assignment_guid')->references('guid')->on('signer_assignments');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_placeholders');
    }
};
