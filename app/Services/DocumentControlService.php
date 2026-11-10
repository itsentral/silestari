<?php

namespace App\Services;

use App\Models\ControlledDocument;
use App\Models\ProcedureEvent;
use App\Models\ProcedureRevision;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentControlService
{
    public function visible(User $user): Builder
    {
        $q = ControlledDocument::query();
        if (! $user->isAdmin()) {
            $q->where(function (Builder $q) use ($user): void {
                $q->where('department', $user->department)->orWhere('creator_id', $user->id)->orWhereHas('currentRevision', fn (Builder $r) => $r->where('reviewer_id', $user->id)->orWhere('approver_id', $user->id)->orWhere('publisher_id', $user->id));
            });
        }

        return $q;
    }

    public function authorize(User $user, ControlledDocument $doc): void
    {
        abort_unless($user->hasModule('documents') && $this->visible($user)->whereKey($doc->id)->exists(), 403);
    }

    public function editable(User $user, ControlledDocument $doc): bool
    {
        return ! $doc->is_archived && (int) $doc->creator_id === (int) $user->id && in_array($doc->currentRevision?->status, ['draft', 'revision', 'correction'], true);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function participants(User $creator, array $data): void
    {
        foreach (['reviewer_id', 'approver_id', 'publisher_id'] as $field) {
            $person = User::find($data[$field]);
            if (! $person?->hasModule('documents')) {
                $this->fail($field, 'Pilih akun aktif yang memiliki akses Document Control.');
            }if (in_array($field, ['reviewer_id', 'approver_id'], true) && (int) $person->id === (int) $creator->id) {
                $this->fail($field, 'Reviewer dan penyetuju harus berbeda dari pembuat dokumen.');
            }
        }
    }

    private function normalized(array $data): array
    {
        $out = [];
        foreach (ProcedureRevision::SECTIONS as $key => $label) {
            $out[$key] = trim((string) ($data['sections'][$key] ?? ''));
        }

        return $out;
    }

    public function image(string $value, int $maxBytes = 400000): string
    {
        if (! preg_match('/^data:(image\/png|image\/jpeg);base64,([A-Za-z0-9+\/=]+)$/D', $value, $m)) {
            $this->fail('signature', 'Gunakan gambar TTD PNG/JPG yang sah.');
        }$raw = base64_decode($m[2], true);
        $info = $raw !== false ? @getimagesizefromstring($raw) : false;
        if (! $raw || strlen($raw) > $maxBytes || ! $info || ($info['mime'] ?? '') !== $m[1] || $info[0] < 20 || $info[1] < 10 || $info[0] > 6000 || $info[1] > 6000) {
            $this->fail('signature', 'Ukuran/format gambar tidak sesuai. Gunakan PNG/JPG yang terbaca.');
        }

        return $value;
    }

    private function snapshot(ControlledDocument $doc, ProcedureRevision $revision): array
    {
        return ['number' => $doc->number, 'department' => $doc->department, 'creator_id' => $doc->creator_id, 'revision' => $revision->version, 'title' => $revision->title, 'process_group' => $revision->process_group, 'sections' => $revision->sections, 'diagram_image' => $revision->diagram_image, 'reviewer_id' => $revision->reviewer_id, 'approver_id' => $revision->approver_id, 'publisher_id' => $revision->publisher_id];
    }

    private function hash(ControlledDocument $doc, ProcedureRevision $revision): string
    {
        return hash('sha256', json_encode($this->snapshot($doc, $revision), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function event(User $actor, ControlledDocument $doc, ProcedureRevision $revision, string $action, ?string $from, string $reason, ?string $signature = null): ProcedureEvent
    {
        return ProcedureEvent::create(['controlled_document_id' => $doc->id, 'procedure_revision_id' => $revision->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'actor_username' => $actor->username ?? $actor->email, 'action' => $action, 'from_status' => $from, 'to_status' => $revision->status, 'cycle' => $revision->cycle, 'reason' => $reason, 'content_hash' => $revision->content_hash, 'snapshot' => $this->snapshot($doc, $revision), 'signature' => $signature, 'created_at' => now()]);
    }

    public function save(User $actor, ?ControlledDocument $document, array $data): ControlledDocument
    {
        abort_unless($actor->hasModule('documents'), 403);
        if ($document) {
            $this->authorize($actor, $document);
        }$this->participants($document ? $document->creator : $actor, $data);
        if (! $actor->isAdmin() && $data['department'] !== $actor->department) {
            $this->fail('department', 'Departemen dokumen harus sesuai akun pembuat.');
        }$sections = $this->normalized($data);
        $diagram = ! empty($data['diagram_image']) ? $this->image($data['diagram_image'], 550000) : null;

        return DB::transaction(function () use ($actor, $document, $data, $sections, $diagram): ControlledDocument {
            if ($document) {
                $doc = ControlledDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
                $r = $doc->currentRevision()->lockForUpdate()->firstOrFail();
                $doc->setRelation('currentRevision', $r);
                abort_unless($this->editable($actor, $doc), 403);
                abort_if($r->lock_version !== (int) $data['lock_version'], 409, 'Dokumen telah berubah. Muat ulang sebelum menyimpan.');
                if ($doc->published_revision_id && ($doc->number !== $data['number'] || $doc->department !== $data['department'])) {
                    $this->fail('number', 'Nomor dan departemen dokumen terbit tidak dapat diubah.');
                }$from = $r->status;
                $doc->number = $data['number'];
                $doc->department = $data['department'];
                $doc->save();
            } else {
                $doc = ControlledDocument::create(['number' => $data['number'], 'department' => $data['department'], 'creator_id' => $actor->id]);
                $r = new ProcedureRevision(['controlled_document_id' => $doc->id, 'version' => 1, 'status' => 'draft', 'cycle' => 0, 'lock_version' => 0]);
                $from = null;
            }
            $r->fill(['title' => $data['title'], 'process_group' => $data['process_group'], 'sections' => $sections, 'diagram_image' => $diagram, 'change_reason' => $data['change_reason'], 'reviewer_id' => $data['reviewer_id'], 'approver_id' => $data['approver_id'], 'publisher_id' => $data['publisher_id'], 'lock_version' => $r->lock_version + 1]);
            $r->content_hash = $this->hash($doc, $r);
            $r->save();
            $doc->current_revision_id = $r->id;
            $doc->save();
            $this->event($actor, $doc, $r, $from ? 'save' : 'create', $from, $data['change_reason']);

            return $doc->fresh(['currentRevision']);
        });
    }

    private function password(User $actor, string $password): void
    {
        $identifier = $actor->username ? ['username' => $actor->username] : ['email' => $actor->email];
        if (! Auth::validate([...$identifier, 'password' => $password, 'is_active' => true])) {
            $this->fail('password', 'Password akun Anda tidak sesuai.');
        }
    }

    private function signed(ProcedureRevision $r, string $action): bool
    {
        return $r->events()->where('cycle', $r->cycle)->where('action', $action)->where('content_hash', $r->content_hash)->whereNotNull('signature')->exists();
    }

    public function act(User $actor, ControlledDocument $document, array $data): ControlledDocument
    {
        $this->authorize($actor, $document);
        $action = $data['action'];
        $reason = trim((string) ($data['reason'] ?? ''));
        if (in_array($action, ['reject', 'revise', 'archive'], true) && mb_strlen($reason) < 5) {
            $this->fail('reason', 'Isi alasan minimal 5 karakter agar perubahan dapat ditelusuri.');
        }
        $signature = null;
        if ($action !== 'revise') {
            $this->password($actor, (string) ($data['password'] ?? ''));
            $signature = $this->image((string) ($data['signature'] ?? ''));
        }

        return DB::transaction(function () use ($actor, $document, $data, $action, $reason, $signature): ControlledDocument {
            $doc = ControlledDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            abort_if($doc->is_archived, 409, 'Dokumen sudah diarsipkan.');
            $r = $doc->currentRevision()->lockForUpdate()->firstOrFail();
            $doc->setRelation('currentRevision', $r);
            abort_if($r->lock_version !== (int) $data['lock_version'], 409, 'Dokumen telah berubah. Muat ulang halaman.');
            $from = $r->status;
            if ($action === 'revise') {
                abort_unless($from === 'published' && (int) $doc->creator_id === (int) $actor->id, 403);
                $new = $r->replicate();
                $new->version = $r->version + 1;
                $new->status = 'revision';
                $new->change_reason = $reason;
                $new->cycle = 0;
                $new->lock_version = 1;
                $new->published_at = null;
                $new->content_hash = $this->hash($doc, $new);
                $new->save();
                $doc->current_revision_id = $new->id;
                $doc->save();
                $this->event($actor, $doc, $new, 'revise', 'published', $reason);

                return $doc->fresh(['currentRevision']);
            }
            $this->participants($doc->creator, ['reviewer_id' => $r->reviewer_id, 'approver_id' => $r->approver_id, 'publisher_id' => $r->publisher_id]);
            if ($action === 'submit') {
                abort_unless($this->editable($actor, $doc), 403);
                foreach (ProcedureRevision::SECTIONS as $key => $label) {
                    if (trim((string) ($r->sections[$key] ?? '')) === '' && ! ($key === 'flowchart' && $r->diagram_image)) {
                        $this->fail('sections', 'Lengkapi bagian '.$label.' sebelum mengajukan review.');
                    }
                }$r->cycle++;
                $r->status = 'review';
            } elseif ($action === 'review') {
                abort_unless($from === 'review' && (int) $r->reviewer_id === (int) $actor->id, 403);
                abort_unless($this->signed($r, 'submit'), 409);
                $r->status = 'approval';
            } elseif ($action === 'approve') {
                abort_unless($from === 'approval' && (int) $r->approver_id === (int) $actor->id, 403);
                abort_unless($this->signed($r, 'submit') && $this->signed($r, 'review'), 409);
                $r->status = 'approved';
            } elseif ($action === 'publish') {
                abort_unless($from === 'approved' && (int) $r->publisher_id === (int) $actor->id, 403);
                foreach (['submit', 'review', 'approve'] as $signedAction) {
                    abort_unless($this->signed($r, $signedAction), 409);
                }$r->status = 'published';
                $r->published_at = now();
                $doc->published_revision_id = $r->id;
            } elseif ($action === 'reject') {
                abort_unless(($from === 'review' && (int) $r->reviewer_id === (int) $actor->id) || ($from === 'approval' && (int) $r->approver_id === (int) $actor->id), 403);
                $r->status = 'correction';
            } elseif ($action === 'archive') {
                abort_unless($this->editable($actor, $doc) && ! $doc->published_revision_id, 403);
                $doc->is_archived = true;
            } else {
                abort(422);
            }
            abort_unless(hash_equals($r->content_hash, $this->hash($doc, $r)), 409, 'Isi dokumen tidak cocok dengan versi tersimpan.');
            $r->lock_version++;
            $r->save();
            $doc->save();
            $this->event($actor, $doc, $r, $action, $from, $reason ?: ['submit' => 'Diajukan pembuat untuk review', 'review' => 'Hasil review disetujui', 'approve' => 'Dokumen disetujui', 'publish' => 'Dokumen diterbitkan'][$action], $signature);
            if ($action === 'publish') {
                $this->syncPublished($doc, $r, $actor);
            }

            return $doc->fresh(['currentRevision']);
        });
    }

    private function syncPublished(ControlledDocument $doc, ProcedureRevision $r, User $actor): void
    {
        app(WorkspaceService::class)->workspace('iso');
        $w = Workspace::where('source', 'iso')->lockForUpdate()->firstOrFail();
        $payload = $w->payload;
        $rows = $payload['records']['documents'] ?? [];
        $id = 'PROC-'.$doc->id;
        $row = ['id' => $id, 'nativeProcedureId' => $doc->id, 'nativeRevisionId' => $r->id, 'title' => $r->title, 'number' => $doc->number, 'revision' => (string) $r->version, 'issueDate' => $r->published_at->timezone('Asia/Jakarta')->format('Y-m-d'), 'status' => 'Berlaku', 'dept' => $doc->department, 'accessDepartment' => $doc->department, 'type' => 'Prosedur', 'link' => route('document-control.published', $doc)];
        $rows = array_values(array_filter($rows, fn (array $old): bool => ($old['id'] ?? '') !== $id));
        $rows[] = $row;
        $payload['records']['documents'] = $rows;
        $history = $w->history ?? [];
        $history[] = ['revision' => $w->revision, 'at' => now()->toIso8601String(), 'user_id' => $actor->id, 'payload' => $w->payload];
        $w->forceFill(['payload' => $payload, 'revision' => $w->revision + 1, 'history' => array_slice($history, -2)])->save();
    }
}
