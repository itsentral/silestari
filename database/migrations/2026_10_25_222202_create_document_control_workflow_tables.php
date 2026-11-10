<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_documents', function (Blueprint $t): void {
            $t->id();
            $t->string('number', 100)->unique();
            $t->string('department', 150)->index();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('current_revision_id')->nullable();
            $t->unsignedBigInteger('published_revision_id')->nullable();
            $t->boolean('is_archived')->default(false)->index();
            $t->timestamps();
        });
        Schema::create('procedure_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('controlled_document_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('title', 200);
            $t->string('process_group', 50);
            $t->string('status', 30)->default('draft')->index();
            $t->longText('sections');
            $t->longText('diagram_image')->nullable();
            $t->text('change_reason');
            $t->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('publisher_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('cycle')->default(0);
            $t->unsignedInteger('lock_version')->default(1);
            $t->string('content_hash', 64);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->unique(['controlled_document_id', 'version']);
        });
        Schema::create('procedure_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('controlled_document_id')->constrained()->restrictOnDelete();
            $t->foreignId('procedure_revision_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('actor_name', 150);
            $t->string('actor_username', 255);
            $t->string('action', 40);
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->unsignedInteger('cycle');
            $t->text('reason');
            $t->string('content_hash', 64);
            $t->longText('snapshot');
            $t->longText('signature')->nullable();
            $t->timestamp('created_at');
            $t->index(['procedure_revision_id', 'cycle', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_events');
        Schema::dropIfExists('procedure_revisions');
        Schema::dropIfExists('controlled_documents');
    }
};
