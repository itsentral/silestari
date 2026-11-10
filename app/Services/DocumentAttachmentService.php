<?php

namespace App\Services;

use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentAttachmentService
{
    public function authorize(User $u, DocumentAttachment $file): void
    {
        abort_unless($u->hasModule('documents') && ($u->isAdmin() || $file->department === $u->department), 403);
    }

    public function save(User $u, array $data): array
    {
        abort_unless($u->hasModule('documents'), 403);
        $d = Validator::make($data, ['kind' => ['required', Rule::in(array_keys(DocumentAttachment::KINDS))], 'number' => 'required|string|max:100', 'title' => 'required|string|max:200', 'department' => 'required|string|max:150', 'document_id' => 'nullable|integer', 'file_name' => 'required|string|max:200', 'file_data' => 'required|string|max:15000000', 'change_summary' => 'required|string|max:3000'])->validate();
        abort_unless($u->isAdmin() || $d['department'] === $u->department, 403);
        if (! preg_match('/^data:[^;,]*;base64,([A-Za-z0-9+\/=]+)$/D', $d['file_data'], $match)) {
            throw ValidationException::withMessages(['file_data' => 'Lampiran tidak valid.']);
        }
        $bytes = base64_decode($match[1], true);
        abort_unless($bytes !== false && strlen($bytes) > 0 && strlen($bytes) <= 10 * 1024 * 1024, 422, 'Lampiran maksimal 10 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = strtolower(pathinfo($d['file_name'], PATHINFO_EXTENSION));
        $allowed = ['pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'doc' => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'], 'xls' => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']];
        abort_unless(isset($allowed[$ext]) && in_array($mime, $allowed[$ext], true), 422, 'Gunakan PDF, Word, Excel, JPG, atau PNG dengan isi yang sesuai.');
        if (in_array($ext, ['docx', 'xlsx'], true)) {
            $temp = tempnam(sys_get_temp_dir(), 'dc-');
            file_put_contents($temp, $bytes);
            $zip = new \ZipArchive;
            try {
                $opened = $zip->open($temp) === true;
                abort_unless($opened && $zip->locateName($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false, 422, 'Isi dokumen Office tidak valid.');
            } finally {
                if (isset($opened) && $opened) {
                    $zip->close();
                }unlink($temp);
            }
            $mime = $allowed[$ext][0];
        }
        $path = 'document-attachments/'.Str::uuid().'.'.$ext;
        try {
            return DB::transaction(function () use ($u, $d, $bytes, $mime, $path): array {
                DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
                $group = (string) Str::uuid();
                $version = 1;
                if (! empty($d['document_id'])) {
                    $previous = DocumentAttachment::findOrFail($d['document_id']);
                    $this->authorize($u, $previous);
                    abort_unless($previous->kind === $d['kind'] && $previous->department === $d['department'] && $previous->number === $d['number'], 422, 'Jenis, nomor dan departemen revisi harus sama dengan dokumen asal.');
                    $group = $previous->group_uuid;
                    $version = DocumentAttachment::withTrashed()->where('group_uuid', $group)->max('version') + 1;
                } else {
                    abort_if(DocumentAttachment::withTrashed()->where('kind', $d['kind'])->where('department', $d['department'])->where('number', $d['number'])->exists(), 422, 'Nomor ini sudah terdaftar. Gunakan Unggah revisi pada dokumen tersebut.');
                }
                if (! Storage::disk('local')->put($path, $bytes, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Lampiran belum tersimpan.');
                }
                $file = DocumentAttachment::create(['group_uuid' => $group, 'kind' => $d['kind'], 'number' => $d['number'], 'title' => $d['title'], 'department' => $d['department'], 'version' => $version, 'original_name' => basename(str_replace('\\', '/', $d['file_name'])), 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'change_summary' => $d['change_summary'], 'uploaded_by' => $u->id, 'uploaded_by_name' => $u->name]);

                return ['id' => $file->id, 'redirect' => route('document-control.index', ['kind' => $d['kind']])];
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
