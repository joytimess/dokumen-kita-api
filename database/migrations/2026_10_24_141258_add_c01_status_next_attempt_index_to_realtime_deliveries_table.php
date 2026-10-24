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
        Schema::table('realtime_deliveries', function (Blueprint $table) {
            $table->index(['status', 'next_attempt_at'], 'realtime_deliveries_status_next_attempt_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('realtime_deliveries', function (Blueprint $table) {
            $table->dropIndex('realtime_deliveries_status_next_attempt_index');
        });
    }
};
