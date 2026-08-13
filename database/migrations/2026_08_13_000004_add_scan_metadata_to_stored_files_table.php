<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->string('scan_engine', 32)->nullable()->after('state');
            $table->string('scan_signature', 255)->nullable()->after('scan_engine');
            $table->timestamp('scan_completed_at')->nullable()->after('scan_signature');
        });
    }

    public function down(): void
    {
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropColumn(['scan_engine', 'scan_signature', 'scan_completed_at']);
        });
    }
};
