<?php

namespace App\Services;

use App\Models\CommunicationEvent;
use App\Models\CommunicationEvidence;
use App\Models\CommunicationMatrix;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommunicationService
{
    public const FIELDS = ['title' => 'Materi komunikasi / konsultasi / partisipasi', 'criteria' => 'Kriteria', 'method' => 'Metode komunikasi / konsultasi / partisipasi', 'language' => 'Bahasa yang digunakan', 'timing' => 'Waktu', 'audience' => 'Sasaran penerima / peserta konsultasi', 'responsible' => 'Penanggung jawab', 'effectiveness' => 'Evaluasi efektivitas', 'control_document' => 'Dokumen pengendalian'];

    public const SLOTS = ['prepared' => 'Dibuat oleh', 'reviewed' => 'Diperiksa oleh', 'approved' => 'Disetujui oleh'];

    public function visible(User $user): Builder
    {
        $q = CommunicationMatrix::query();
        if (! $user->isAdmin()) {
            $q->where('department', $user->department);
        }

        return $q;
    }

    public function authorize(User $user, CommunicationMatrix $matrix): void
    {
        abort_unless($user->hasModule('communication') && $this->visible($user)->whereKey($matrix->id)->exists(), 403);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function event(User $actor, CommunicationMatrix $matrix, string $action, array $extra = []): void
    {
        CommunicationEvent::create(['communication_matrix_id' => $matrix->id, 'actor_id' => $actor->id, 'action' => $action, 'revision' => $matrix->revision,
            'snapshot' => ['actor_name' => $actor->name, 'actor_username' => $actor->username ?? $actor->email, 'payload' => $matrix->payload, 'signatures' => $matrix->signatures, 'evidence' => $matrix->evidence()->get()->map(fn (CommunicationEvidence $e) => ['id' => $e->id, 'material_id' => $e->material_id, 'name' => $e->original_name, 'sha256' => $e->sha256])->all(), ...$extra], 'created_at' => now()]);
    }

    public function create(User $actor, array $data): CommunicationMatrix
    {
        abort_unless($actor->hasModule('communication'), 403);
        abort_unless($actor->isAdmin() || $data['department'] === $actor->department, 403);

        return DB::transaction(function () use ($actor, $data): CommunicationMatrix {
            DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
            abort_if(CommunicationMatrix::where('year', $data['year'])->where('department', $data['department'])->exists(), 409, 'Matriks tahun dan departemen ini sudah ada. Buka matriks yang tersedia.');
            $payload = ['number' => '', 'document_revision' => '00', 'edition' => '', 'sources' => [], 'materials' => [], 'signers' => ['prepared' => $actor->id, 'reviewed' => null, 'approved' => null]];
            if ($data['template']) {
                $template = json_decode(file_get_contents(resource_path('templates/communication.json')), true, 512, JSON_THROW_ON_ERROR);
                $ids = [];
                foreach ($template['sources'] as $s) {
                    $id = (string) Str::uuid();
                    $ids[$s['key']] = $id;
                    $payload['sources'][] = ['id' => $id, 'name' => $s['name']];
                }
                foreach ($template['materials'] as $row) {
                    $key = $row['source_key'];
                    unset($row['source_key']);
                    $payload['materials'][] = ['id' => (string) Str::uuid(), 'source_id' => $ids[$key], ...$row];
                }
            }
            $matrix = CommunicationMatrix::create(['year' => $data['year'], 'department' => trim($data['department']), 'creator_id' => $actor->id, 'payload' => $payload, 'signatures' => [], 'revision' => 1]);
            $this->event($actor, $matrix, 'Matriks dibuat');

            return $matrix;
        });
    }

    private function locked(User $actor, CommunicationMatrix $matrix, int $revision): CommunicationMatrix
    {
        $matrix = CommunicationMatrix::whereKey($matrix->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $matrix);
        abort_if($matrix->revision !== $revision, 409, 'Data telah berubah. Muat ulang halaman sebelum melanjutkan.');

        return $matrix;
    }

    private function index(array $rows, string $id): int
    {
        foreach ($rows as $i => $row) {
            if ($row['id'] === $id) {
                return $i;
            }
        } abort(404, 'Sumber atau materi tidak ditemukan pada matriks ini.');
    }

    private function changed(User $actor, CommunicationMatrix $matrix, array $payload, string $action, array $extra = []): CommunicationMatrix
    {
        $matrix->payload = $payload;
        $matrix->signatures = [];
        $matrix->revision++;
        $matrix->save();
        $this->event($actor, $matrix, $action, $extra);

        return $matrix;
    }

    public function act(User $actor, CommunicationMatrix $matrix, array $data): CommunicationMatrix
    {
        return DB::transaction(function () use ($actor, $matrix, $data): CommunicationMatrix {
            $matrix = $this->locked($actor, $matrix, $data['revision']);
            $p = $matrix->payload;
            $action = $data['action'];
            if ($action === 'copy') {
                abort_if($matrix->year >= 2100, 422, 'Tahun salinan di luar batas.');
                DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
                abort_if(CommunicationMatrix::where('year', $matrix->year + 1)->where('department', $matrix->department)->exists(), 409, 'Matriks tahun berikutnya sudah ada; data tidak ditimpa.');
                $ids = [];
                foreach ($p['sources'] as &$s) {
                    $old = $s['id'];
                    $s['id'] = (string) Str::uuid();
                    $ids[$old] = $s['id'];
                } unset($s);
                foreach ($p['materials'] as &$row) {
                    $row['id'] = (string) Str::uuid();
                    $row['source_id'] = $ids[$row['source_id']];
                    $row['effectiveness'] = '';
                    $row['control_document'] = '';
                } unset($row);
                $p['signers'] = ['prepared' => $actor->id, 'reviewed' => null, 'approved' => null];
                $p['document_revision'] = '00';
                $p['edition'] = '';
                $copy = CommunicationMatrix::create(['year' => $matrix->year + 1, 'department' => $matrix->department, 'creator_id' => $actor->id, 'copied_from_id' => $matrix->id, 'payload' => $p, 'signatures' => [], 'revision' => 1]);
                $this->event($actor, $copy, 'Disalin dari tahun '.$matrix->year, ['copied_from_id' => $matrix->id]);

                return $copy;
            }
            if ($action === 'sign') {
                $slot = $data['slot'];
                abort_unless((int) ($p['signers'][$slot] ?? 0) === $actor->id, 403, 'Hanya akun yang ditunjuk dapat mengisi TTD ini.');
                if (! Auth::validate(['email' => $actor->email, 'username' => $actor->username, 'password' => $data['password']])) {
                    $this->fail('password', 'Password akun tidak sesuai.');
                }
                $image = app(DocumentControlService::class)->image($data['signature']);
                if (count($p['materials']) === 0) {
                    $this->fail('signature', 'Tambahkan materi komunikasi sebelum menandatangani.');
                }
                $hash = $this->digest($matrix);
                $signatures = $matrix->signatures;
                $signatures[$slot] = ['actor_id' => $actor->id, 'name' => $actor->name, 'username' => $actor->username ?? $actor->email, 'at' => now()->toIso8601String(), 'image' => $image, 'hash' => $hash];
                $matrix->signatures = $signatures;
                $matrix->revision++;
                $matrix->save();
                $this->event($actor, $matrix, 'TTD '.self::SLOTS[$slot]);

                return $matrix;
            }
            if ($action === 'metadata') {
                $p['number'] = trim($data['number'] ?? '');
                $p['edition'] = trim($data['edition'] ?? '');
                $p['document_revision'] = trim($data['document_revision'] ?? '');
                foreach (self::SLOTS as $slot => $label) {
                    $id = $data[$slot] ?? null;
                    if ($id) {
                        $person = User::find($id);
                        if (! $person?->hasModule('communication') || (! $person->isAdmin() && $person->department !== $matrix->department)) {
                            $this->fail($slot, 'Pilih akun aktif yang berhak mengakses departemen matriks ini.');
                        }
                    }
                    $p['signers'][$slot] = $id ? (int) $id : null;
                }
            } elseif ($action === 'add_source') {
                abort_if(count($p['sources']) >= 200, 422, 'Maksimal 200 sumber per matriks.');
                $p['sources'][] = ['id' => (string) Str::uuid(), 'name' => trim($data['name'])];
            } elseif (in_array($action, ['update_source', 'delete_source'], true)) {
                $i = $this->index($p['sources'], $data['source_id']);
                if ($action === 'update_source') {
                    $p['sources'][$i]['name'] = trim($data['name']);
                } else {
                    $ids = array_column(array_filter($p['materials'], fn (array $r) => $r['source_id'] === $data['source_id']), 'id');
                    $matrix->evidence()->whereIn('material_id', $ids)->get()->each->delete();
                    unset($p['sources'][$i]);
                    $p['sources'] = array_values($p['sources']);
                    $p['materials'] = array_values(array_filter($p['materials'], fn (array $r) => $r['source_id'] !== $data['source_id']));
                }
            } elseif (in_array($action, ['add_material', 'update_material'], true)) {
                $this->index($p['sources'], $data['source_id']);
                $row = ['id' => $action === 'add_material' ? (string) Str::uuid() : $data['material_id'], 'source_id' => $data['source_id']];
                foreach (self::FIELDS as $key => $label) {
                    $row[$key] = trim($data[$key] ?? '');
                }
                if ($row['title'] === '') {
                    $this->fail('title', 'Materi komunikasi wajib diisi.');
                }
                if ($action === 'add_material') {
                    abort_if(count($p['materials']) >= 1000, 422, 'Maksimal 1.000 materi per matriks.');
                    $p['materials'][] = $row;
                } else {
                    $p['materials'][$this->index($p['materials'], $row['id'])] = $row;
                }
            } elseif ($action === 'delete_material') {
                $i = $this->index($p['materials'], $data['material_id']);
                unset($p['materials'][$i]);
                $p['materials'] = array_values($p['materials']);
                $matrix->evidence()->where('material_id', $data['material_id'])->get()->each->delete();
            } elseif ($action === 'delete_evidence') {
                $matrix->evidence()->whereKey($data['evidence_id'])->firstOrFail()->delete();
            }
            $labels = ['metadata' => 'Identitas dan akun TTD diubah', 'add_source' => 'Sumber ditambahkan', 'update_source' => 'Sumber diubah', 'delete_source' => 'Sumber dan materi terkait dihapus', 'add_material' => 'Materi ditambahkan', 'update_material' => 'Materi diubah', 'delete_material' => 'Materi dihapus', 'delete_evidence' => 'Bukti dihapus'];

            return $this->changed($actor, $matrix, $p, $labels[$action]);
        });
    }

    public function digest(CommunicationMatrix $matrix): string
    {
        return hash('sha256', json_encode(['year' => $matrix->year, 'department' => $matrix->department, 'payload' => $matrix->payload, 'evidence' => $matrix->evidence()->orderBy('id')->get(['id', 'material_id', 'sha256'])->toArray()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function attach(User $actor, CommunicationMatrix $matrix, array $data): CommunicationMatrix
    {
        Validator::make($data, ['revision' => 'required|integer|min:1', 'material_id' => 'required|uuid', 'file_name' => 'required|string|max:200', 'file_data' => 'required|string|max:15000000'])->validate();
        if (! preg_match('/^data:(application\/pdf|image\/png|image\/jpeg);base64,([A-Za-z0-9+\/=]+)$/D', $data['file_data'], $m)) {
            $this->fail('file_data', 'Gunakan bukti PDF, JPG, atau PNG.');
        }
        $bytes = base64_decode($m[2], true);
        $mime = $bytes ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : '';
        $ext = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
        if (! $ext || $mime !== $m[1] || strlen($bytes) > 10 * 1024 * 1024) {
            $this->fail('file_data', 'Format bukti tidak sesuai atau ukuran melebihi 10 MB.');
        }
        $path = 'communication-evidence/'.Str::uuid().'.'.$ext;
        try {
            return DB::transaction(function () use ($actor, $matrix, $data, $bytes, $mime, $path): CommunicationMatrix {
                $matrix = $this->locked($actor, $matrix, (int) $data['revision']);
                $this->index($matrix->payload['materials'], $data['material_id']);
                abort_if($matrix->evidence()->where('material_id', $data['material_id'])->count() >= 20, 422, 'Maksimal 20 bukti per materi.');
                if (! Storage::disk('local')->put($path, $bytes, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Bukti belum tersimpan.');
                }
                $e = $matrix->evidence()->create(['material_id' => $data['material_id'], 'original_name' => basename(str_replace('\\', '/', $data['file_name'])), 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256',$bytes), 'uploaded_by' => $actor->id, 'uploaded_by_name' => $actor->name]);

                return $this->changed($actor,$matrix,$matrix->payload,'Bukti komunikasi diunggah',['evidence_id' => $e->id]);
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
