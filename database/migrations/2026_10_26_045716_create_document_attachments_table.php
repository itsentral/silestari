<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('group_uuid');
            $t->string('kind', 20);
            $t->string('number', 100);
            $t->string('title', 200);
            $t->string('department', 150);
            $t->unsignedInteger('version');
            $t->text('original_name');
            $t->string('path');
            $t->string('mime', 120);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->text('change_summary');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->text('uploaded_by_name');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['group_uuid', 'version']);
            $t->index(['kind', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_attachments');
    }
};
