<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropIndex(['owner_id', 'sha256']);
            $table->unique(['owner_id', 'sha256'], 'stored_files_owner_sha256_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropUnique('stored_files_owner_sha256_unique');
            $table->index(['owner_id', 'sha256']);
        });
    }
};
