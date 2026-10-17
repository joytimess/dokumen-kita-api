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
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('owner_guid', 36);
            $table->string('name');
            $table->string('normalized_name');
            $table->string('color_token', 16)->default('gray');
            $table->string('description', 500)->nullable();
            $table->foreign('owner_guid')->references('guid')->on('users');
            $table->unique(['owner_guid', 'normalized_name'], 'document_categories_owner_name_unique');
            $table->unique(['guid', 'owner_guid'], 'document_categories_guid_owner_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};
