<?php

namespace App\Services;

use App\Models\PolicyDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PolicyDocumentService
{
    public function save(User $user, int $revision, array $data): array
    {
        abort_unless($user->hasModule('policy'), 403);
        foreach (['title', 'change_summary'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }
        Validator::make($data, ['title' => 'required|string|max:200', 'file_name' => 'required|string|max:200', 'file_data' => 'required|string|max:15000000', 'change_summary' => 'required|string|max:3000'])->validate();
        if (! preg_match('/^data:(application\/pdf|image\/png|image\/jpeg);base64,([A-Za-z0-9+\/=]+)$/D', $data['file_data'], $match)) {
            throw ValidationException::withMessages(['file_data' => 'Gunakan dokumen PDF, JPG, atau PNG.']);
        }
        $bytes = base64_decode($match[2], true);
        if ($bytes === false || strlen($bytes) > 10 * 1024 * 1024 || strlen($bytes) === 0) {
            throw ValidationException::withMessages(['file_data' => 'Ukuran dokumen maksimal 10 MB.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg'][$mime] ?? null;
        if (! $extension || $mime !== $match[1]) {
            throw ValidationException::withMessages(['file_data' => 'Isi file tidak sesuai format PDF/JPG/PNG.']);
        }
        $path = 'policy-documents/'.Str::uuid().'.'.$extension;
        try {
            return DB::transaction(function () use ($user, $revision, $data, $bytes, $mime, $path): array {
                DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
                $latest = PolicyDocument::withTrashed()->orderByDesc('version')->first();
                abort_if(($latest?->version ?? 0) !== $revision, 409, 'Versi kebijakan telah berubah. Muat ulang halaman sebelum mengunggah.');
                if (! Storage::disk('local')->put($path, $bytes, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Dokumen belum tersimpan.');
                }
                $doc = PolicyDocument::create(['version' => $revision + 1, 'title' => $data['title'], 'original_name' => basename(str_replace('\\', '/', $data['file_name'])), 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'change_summary' => $data['change_summary'], 'uploaded_by' => $user->id, 'uploaded_by_name' => $user->name]);

                return ['id' => $doc->id, 'version' => $doc->version, 'redirect' => route('policy.index')];
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
