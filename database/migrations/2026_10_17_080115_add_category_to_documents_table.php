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
        Schema::table('documents', function (Blueprint $table) {
            $table->char('category_guid', 36)->nullable()->after('owner_guid');
            $table->index(['owner_guid', 'category_guid'], 'documents_owner_category_index');
            $table->foreign(['category_guid', 'owner_guid'], 'documents_category_owner_foreign')
                ->references(['guid', 'owner_guid'])->on('document_categories')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign('documents_category_owner_foreign');
            $table->dropIndex('documents_owner_category_index');
            $table->dropColumn('category_guid');
        });
    }
};
