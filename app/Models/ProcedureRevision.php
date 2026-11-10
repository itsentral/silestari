<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['controlled_document_id', 'version', 'title', 'process_group', 'status', 'sections', 'diagram_image', 'change_reason', 'reviewer_id', 'approver_id', 'publisher_id', 'cycle', 'lock_version', 'content_hash', 'published_at'])]
class ProcedureRevision extends Model
{
    public const SECTIONS = ['purpose' => 'Tujuan', 'scope' => 'Ruang Lingkup', 'definitions' => 'Istilah & Definisi', 'references' => 'Referensi', 'process_risks' => 'Risiko & Peluang Proses', 'flowchart' => 'Diagram Alir Proses', 'details' => 'Rincian Prosedur', 'related_documents' => 'Dokumen Terkait'];

    public const STATUSES = ['draft' => 'Draft', 'review' => 'Review', 'correction' => 'Perbaikan', 'approval' => 'Persetujuan', 'approved' => 'Siap Published', 'revision' => 'Revisi', 'published' => 'Published'];

    protected function casts(): array
    {
        return ['reviewer_id' => 'integer', 'approver_id' => 'integer', 'publisher_id' => 'integer', 'sections' => 'encrypted:array', 'diagram_image' => 'encrypted', 'version' => 'integer', 'cycle' => 'integer', 'lock_version' => 'integer', 'published_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ControlledDocument::class, 'controlled_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProcedureEvent::class);
    }
}
