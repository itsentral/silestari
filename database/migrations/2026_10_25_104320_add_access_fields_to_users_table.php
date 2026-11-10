<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('user');
            $table->string('department', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('allowed_modules')->nullable();
            $table->unsignedInteger('auth_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'department', 'is_active', 'allowed_modules', 'auth_version']);
        });
    }
};
