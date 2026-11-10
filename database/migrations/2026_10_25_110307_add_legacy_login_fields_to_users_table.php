<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('username', 50)->nullable()->unique();
            $table->string('legacy_account_id')->nullable()->unique();
            $table->text('legacy_password_hash')->nullable();
            $table->text('legacy_password_salt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['username', 'legacy_account_id', 'legacy_password_hash', 'legacy_password_salt']);
        });
    }
};
