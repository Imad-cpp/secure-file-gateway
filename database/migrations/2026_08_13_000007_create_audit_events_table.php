<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 120);
            $table->string('target_type', 80)->nullable();
            $table->uuid('target_id')->nullable();
            $table->string('outcome', 40);
            $table->uuid('request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
