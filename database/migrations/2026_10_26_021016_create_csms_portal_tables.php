<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csms_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('internal_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $t->string('name');
            $t->string('email')->nullable()->unique();
            $t->string('username')->nullable();
            $t->string('password');
            $t->json('roles');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('auth_version')->default(1);
            $t->timestamps();
        });
        Schema::create('csms_questions', function (Blueprint $t) {
            $t->string('id', 8)->primary();
            $t->string('group');
            $t->text('text');
            $t->unsignedSmallInteger('max');
            $t->unsignedSmallInteger('position');
        });
        Schema::create('csms_vendors', function (Blueprint $t) {
            $t->string('id', 48)->primary();
            $t->foreignId('owner_id')->constrained('csms_accounts')->restrictOnDelete();
            foreach (['name', 'nib', 'npwp', 'pic', 'email', 'phone', 'business', 'ossRisk', 'branch', 'evaluator', 'validator', 'fieldLocation', 'fieldResult'] as $c) {
                $t->string($c)->default('');
            }
            foreach (['address', 'scope', 'notes'] as $c) {
                $t->text($c)->nullable();
            }
            $t->string('status')->default('Menunggu Pengisian')->index();
            $t->date('docDate')->nullable();
            $t->date('fieldDate')->nullable();
            $t->string('finalRisk')->nullable();
            $t->timestamp('finalizedAt')->nullable();
            $t->json('final_snapshot')->nullable();
            $t->timestamps();
        });
        Schema::create('csms_answers', function (Blueprint $t) {
            $t->id();
            $t->string('vendor_id', 48);
            $t->foreign('vendor_id')->references('id')->on('csms_vendors')->cascadeOnDelete();
            $t->string('question_id', 8);
            $t->foreign('question_id')->references('id')->on('csms_questions');
            $t->string('answer')->default('');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['vendor_id', 'question_id']);
        });
        Schema::create('csms_evidence', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('vendor_id', 48);
            $t->foreign('vendor_id')->references('id')->on('csms_vendors')->cascadeOnDelete();
            $t->string('question_id', 8);
            $t->foreign('question_id')->references('id')->on('csms_questions');
            $t->foreignId('uploaded_by')->constrained('csms_accounts');
            $t->string('name');
            $t->string('path');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('csms_assessments', function (Blueprint $t) {
            $t->id();
            $t->string('vendor_id', 48);
            $t->foreign('vendor_id')->references('id')->on('csms_vendors')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('csms_accounts')->restrictOnDelete();
            $t->string('stage', 20);
            $t->json('metadata');
            $t->timestamps();
            $t->unique(['vendor_id', 'user_id', 'stage']);
        });
        Schema::create('csms_assessment_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assessment_id')->constrained('csms_assessments')->cascadeOnDelete();
            $t->string('question_id', 8);
            $t->foreign('question_id')->references('id')->on('csms_questions');
            $t->decimal('score', 3, 2);
            $t->text('comment')->nullable();
            $t->unique(['assessment_id', 'question_id']);
        });
        Schema::create('csms_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('csms_accounts')->nullOnDelete();
            $t->string('vendor_id', 48)->nullable()->index();
            $t->string('action');
            $t->json('details')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        foreach (json_decode(file_get_contents(resource_path('templates/csms-questions.json')), true, 512, JSON_THROW_ON_ERROR) as $i => $q) {
            $q['position'] = $i;
            DB::table('csms_questions')->insert($q);
        }
    }

    public function down(): void
    {
        foreach (['csms_audit_logs', 'csms_assessment_scores', 'csms_assessments', 'csms_evidence', 'csms_answers', 'csms_vendors', 'csms_questions'] as $table) {
            Schema::dropIfExists($table);
        }Schema::dropIfExists('csms_accounts');
    }
};
