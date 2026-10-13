<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_responses', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('assignment_guid', 36);
            $table->string('response_type', 24);
            $table->text('reason');
            $table->foreign('assignment_guid')->references('guid')->on('signer_assignments');
            $table->unique('assignment_guid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_responses');
    }
};
