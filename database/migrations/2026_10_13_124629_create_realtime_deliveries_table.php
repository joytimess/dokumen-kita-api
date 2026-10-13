<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('notification_guid', 36);
            $table->string('status', 16)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreign('notification_guid')->references('guid')->on('user_notifications');
            $table->unique('notification_guid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_deliveries');
    }
};
