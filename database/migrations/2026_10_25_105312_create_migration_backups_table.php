<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 20);
            $table->string('filename');
            $table->char('checksum', 64)->unique();
            $table->unsignedInteger('record_count');
            $table->json('record_counts');
            $table->longText('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_backups');
    }
};
