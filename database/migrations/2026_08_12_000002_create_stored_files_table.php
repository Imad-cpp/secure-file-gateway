<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('detected_mime_type', 127)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('quarantine_object_key', 1024)->nullable();
            $table->string('clean_object_key', 1024)->nullable();
            $table->string('state', 32)->default('QUARANTINED');
            $table->timestamps();

            $table->index(['owner_id', 'state']);
            $table->index(['owner_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
