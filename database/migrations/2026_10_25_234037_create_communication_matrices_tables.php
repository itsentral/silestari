<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_matrices', function (Blueprint $t): void {
            $t->id();
            $t->unsignedSmallInteger('year');
            $t->string('department', 120);
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('copied_from_id')->nullable()->constrained('communication_matrices')->restrictOnDelete();
            $t->longText('payload');
            $t->longText('signatures');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['year', 'department']);
        });
        Schema::create('communication_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('communication_matrix_id')->constrained()->restrictOnDelete();
            $t->uuid('material_id');
            $t->text('original_name');
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->text('uploaded_by_name');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['communication_matrix_id', 'material_id']);
        });
        Schema::create('communication_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('communication_matrix_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 60);
            $t->unsignedInteger('revision');
            $t->longText('snapshot');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_events');
        Schema::dropIfExists('communication_evidence');
        Schema::dropIfExists('communication_matrices');
    }
};
