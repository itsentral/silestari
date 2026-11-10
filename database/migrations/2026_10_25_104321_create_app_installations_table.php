<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_installations', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('completed_at')->nullable();
        });
        DB::table('app_installations')->insert(['id' => 1, 'completed_at' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_installations');
    }
};
