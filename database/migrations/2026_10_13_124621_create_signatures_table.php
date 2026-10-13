<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->char('guid', 36)->unique();
            $table->char('assignment_guid', 36);
            $table->char('asset_guid', 36);
            $table->char('version_content_hash', 64);
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('signed_at');
            $table->foreign('assignment_guid')->references('guid')->on('signer_assignments');
            $table->foreign('asset_guid')->references('guid')->on('signature_assets');
            $table->unique('assignment_guid');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
