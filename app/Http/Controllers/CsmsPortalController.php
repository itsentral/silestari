<?php

namespace App\Http\Controllers;

use App\Models\CsmsAccount;
use App\Models\CsmsVendor;
use App\Services\CsmsPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsmsPortalController extends Controller
{
    public function state(Request $r): array
    {
        $q = CsmsVendor::orderBy('created_at');
        if (! $r->user()->hasRole('verifikator', 'validator', 'pengadaan')) {
            $q->where('owner_id', $r->user()->id);
        }

        return ['session' => $r->user()->publicData(), 'vendors' => $q->get()->map(fn ($v) => CsmsPortal::serialize($v)), 'accounts' => $r->user()->hasRole('admin') ? CsmsAccount::where('active', true)->get()->map(fn ($u) => $u->publicData()) : []];
    }

    public function profile(Request $r, ?CsmsVendor $vendor = null): array
    {
        CsmsPortal::role($r->user(), 'vendor');
        $rules = [];
        foreach (['name', 'nib', 'pic', 'email', 'business', 'address', 'scope'] as $f) {
            $rules[$f] = 'required|string|max:'.(in_array($f, ['address', 'scope']) ? 5000 : 255);
        }
        foreach (['npwp', 'phone', 'ossRisk', 'branch', 'notes'] as $f) {
            $rules[$f] = 'nullable|string|max:'.($f === 'notes' ? 5000 : 255);
        }$rules['email'] = 'required|email|max:255';
        $d = $r->validate($rules);

        return DB::transaction(function () use ($r, $vendor, $d) {
            if ($vendor) {
                $vendor = CsmsVendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
                CsmsPortal::authorize($r->user(), $vendor);
                abort_unless(in_array($vendor->status, ['Menunggu Pengisian', 'Perlu Perbaikan']), 409, 'Profil sudah dikirim dan terkunci.');
            } else {
                $vendor = new CsmsVendor(['id' => 'CSMS-'.date('Y').'-'.Str::upper(Str::random(12)), 'owner_id' => $r->user()->id, 'status' => 'Menunggu Pengisian']);
            }
            $vendor->fill(array_map(fn ($x) => $x ?? '', $d));
            $vendor->save();
            CsmsPortal::audit('vendor_saved', $vendor->id);

            return CsmsPortal::serialize($vendor);
        });
    }

    public function answers(Request $r, CsmsVendor $vendor): array
    {
        CsmsPortal::role($r->user(), 'vendor');
        CsmsPortal::authorize($r->user(), $vendor);
        $d = $r->validate(['answers' => 'required|array', 'answers.*' => 'required|array:question_id,answer,note', 'answers.*.question_id' => 'required|distinct|exists:csms_questions,id', 'answers.*.answer' => ['required', Rule::in(['Ya', 'Tidak'])], 'answers.*.note' => 'nullable|string|max:5000']);
        abort_unless(count($d['answers']) === DB::table('csms_questions')->count(), 422, 'Jawab semua pertanyaan sebelum mengirim.');

        return DB::transaction(function () use ($vendor, $d) {
            $v = CsmsVendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($v->status, ['Menunggu Pengisian', 'Perlu Perbaikan']), 409, 'Pengajuan sedang diproses atau sudah final.');
            foreach ($d['answers'] as $a) {
                DB::table('csms_answers')->updateOrInsert(['vendor_id' => $v->id, 'question_id' => $a['question_id']], ['answer' => $a['answer'], 'note' => $a['note'] ?? '', 'updated_at' => now(), 'created_at' => now()]);
            }
            // Returned submissions start a fresh scoring round. Previous scores remain in audit history.
            if ($v->status === 'Perlu Perbaikan') {
                CsmsPortal::audit('previous_round_archived', $v->id, CsmsPortal::serialize($v));
                DB::table('csms_assessments')->where('vendor_id', $v->id)->delete();
            }
            $v->update(['status' => 'Menunggu Verifikasi Dokumen']);
            CsmsPortal::audit('questionnaire_submitted', $v->id);

            return CsmsPortal::serialize($v);
        });
    }

    public function upload(Request $r, CsmsVendor $vendor): array
    {
        CsmsPortal::role($r->user(), 'vendor');
        CsmsPortal::authorize($r->user(), $vendor);
        $d = $r->validate(['question_id' => 'required|exists:csms_questions,id', 'file' => 'required|file|max:15360|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png|extensions:pdf,doc,docx,xls,xlsx,jpg,jpeg,png']);

        return DB::transaction(function () use ($r, $vendor, $d) {
            $v = CsmsVendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($v->status, ['Menunggu Pengisian', 'Perlu Perbaikan']), 409, 'Lampiran sudah terkunci.');
            $file = $r->file('file');
            $id = (string) Str::uuid();
            $path = $file->store('csms-evidence/'.$vendor->id, 'local');
            try {
                DB::table('csms_evidence')->insert(['id' => $id, 'vendor_id' => $v->id, 'question_id' => $d['question_id'], 'uploaded_by' => $r->user()->id, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 240), 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'created_at' => now(), 'updated_at' => now()]);
                CsmsPortal::audit('evidence_uploaded', $v->id, ['id' => $id]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }

            return ['id' => $id, 'name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'type' => $file->getMimeType()];
        });
    }

    public function download(Request $r, string $id): StreamedResponse
    {
        $f = DB::table('csms_evidence')->where('id', $id)->first();
        abort_unless($f, 404);
        CsmsPortal::authorize($r->user(), CsmsVendor::findOrFail($f->vendor_id));
        abort_unless(Storage::disk('local')->exists($f->path), 404, 'Berkas bukti tidak ditemukan.');
        CsmsPortal::audit('evidence_downloaded', $f->vendor_id, ['id' => $id]);

        return Storage::disk('local')->download($f->path, $f->name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function assessment(Request $r, CsmsVendor $vendor, string $stage): array
    {
        abort_unless(in_array($stage, ['review', 'validation']), 404);
        CsmsPortal::role($r->user(), $stage === 'review' ? 'verifikator' : 'validator');
        $d = $r->validate(['scores' => 'required|array', 'scores.*.question_id' => 'required|distinct|exists:csms_questions,id', 'scores.*.score' => ['required', 'numeric', Rule::in([0, 0.25, 0.5, 0.75, 1])], 'scores.*.comment' => 'nullable|string|max:5000', 'docDate' => 'nullable|date', 'fieldDate' => 'nullable|date', 'evaluator' => 'nullable|string|max:255', 'validator' => 'nullable|string|max:255', 'fieldLocation' => 'nullable|string|max:255', 'fieldResult' => ['nullable', Rule::in(['Belum dilakukan', 'Sesuai', 'Sesuai dengan catatan', 'Tidak sesuai'])], 'action' => ['required', Rule::in(['save', 'advance', 'return', 'finalize'])]]);
        abort_unless(count($d['scores']) === DB::table('csms_questions')->count(), 422, 'Seluruh kriteria wajib dinilai.');
        abort_if(($stage === 'review' && $d['action'] === 'finalize') || ($stage === 'validation' && in_array($d['action'], ['advance', 'return'])), 422);

        return DB::transaction(function () use ($r, $vendor, $stage, $d) {
            $v = CsmsVendor::whereKey($vendor->id)->lockForUpdate()->firstOrFail();
            $expected = $stage === 'review' ? 'Menunggu Verifikasi Dokumen' : 'Menunggu Validasi Lapangan';
            abort_unless($v->status === $expected, 409, 'Tahap penilaian tidak sesuai atau sudah dikunci.');
            if ($d['action'] === 'finalize') {
                abort_unless(! empty($d['fieldDate']) && ! empty($d['fieldLocation']) && ($d['fieldResult'] ?? 'Belum dilakukan') !== 'Belum dilakukan', 422, 'Lengkapi tanggal, lokasi, dan hasil validasi.');
            }
            $metadata = array_intersect_key($d, array_flip($stage === 'review' ? ['docDate', 'evaluator'] : ['fieldDate', 'validator', 'fieldLocation', 'fieldResult']));
            $key = ['vendor_id' => $v->id, 'user_id' => $r->user()->id, 'stage' => $stage];
            $a = DB::table('csms_assessments')->where($key)->first();
            $values = ['metadata' => json_encode($metadata), 'updated_at' => now()];
            if ($a) {
                $aid = $a->id;
                DB::table('csms_assessments')->where('id', $aid)->update($values);
            } else {
                $aid = DB::table('csms_assessments')->insertGetId($key + $values + ['created_at' => now()]);
            }
            foreach ($d['scores'] as $s) {
                DB::table('csms_assessment_scores')->updateOrInsert(['assessment_id' => $aid, 'question_id' => $s['question_id']], ['score' => $s['score'], 'comment' => $s['comment'] ?? '']);
            }
            foreach ($metadata as $k => $val) {
                $v->$k = $val ?? (in_array($k, ['docDate', 'fieldDate']) ? null : '');
            }
            if ($d['action'] === 'advance') {
                $v->status = 'Menunggu Validasi Lapangan';
            }
            if ($d['action'] === 'return') {
                $v->status = 'Perlu Perbaikan';
            }
            if ($d['action'] === 'finalize') {
                $v->status = 'Tervalidasi';
                $total = CsmsPortal::total($v->id, 'validation');
                $v->finalRisk = CsmsPortal::category($total / 565 * 100);
                $v->finalizedAt = now();
                $v->final_snapshot = ['total' => $total, 'percentage' => $total / 565 * 100, 'category' => $v->finalRisk, 'scope' => $v->scope, 'name' => $v->name, 'fieldDate' => $v->fieldDate];
            }
            $v->save();
            CsmsPortal::audit($stage.'_'.$d['action'], $v->id, ['assessment_id' => $aid, 'scores' => $d['scores'], 'metadata' => $metadata]);

            return CsmsPortal::serialize($v);
        });
    }

    public function account(Request $r): array
    {
        CsmsPortal::role($r->user(), 'admin');
        $d = $r->validate(['name' => 'required|string|max:255', 'username' => 'required|email|max:255|unique:csms_accounts,email', 'password' => CsmsPortal::passwordRules(), 'role' => 'required|array|min:1', 'role.*' => ['required', Rule::in(['admin', 'verifikator', 'validator', 'pengadaan'])]]);
        $u = CsmsAccount::create(['name' => $d['name'], 'email' => strtolower($d['username']), 'password' => $d['password'], 'roles' => in_array('admin', $d['role']) ? ['admin'] : array_values(array_unique($d['role']))]);
        CsmsPortal::audit('account_created', null, ['user_id' => $u->id]);

        return $u->publicData();
    }

    public function resetPassword(Request $r, CsmsAccount $user): array
    {
        CsmsPortal::role($r->user(), 'admin');
        abort_if(in_array('vendor', $user->roles ?? []), 403);
        $d = $r->validate(['password' => CsmsPortal::passwordRules()]);
        abort_if($user->internal_user_id, 403, 'Password akun SILESTARI diatur dari menu akun utama.');
        $user->forceFill(['password' => $d['password'], 'auth_version' => $user->auth_version + 1])->save();
        CsmsPortal::audit('account_password_reset', null, ['user_id' => $user->id]);

        return ['ok' => true];
    }

    public function deactivate(Request $r, CsmsAccount $user): array
    {
        CsmsPortal::role($r->user(), 'admin');
        abort_if($user->id === $r->user()->id || in_array('admin', $user->roles ?? []) || in_array('vendor', $user->roles ?? []), 422, 'Akun sendiri/admin/vendor tidak dapat dihapus dari menu internal.');
        $user->forceFill(['active' => false, 'auth_version' => $user->auth_version + 1])->save();
        CsmsPortal::audit('account_deactivated', null, ['user_id' => $user->id]);

        return ['ok' => true];
    }

    public function export(Request $r): JsonResponse
    {
        CsmsPortal::audit('export');

        return response()->json($this->state($r)['vendors'])->header('Content-Disposition', 'attachment; filename="csms-vendors.json"');
    }

    public function chunks(Request $r, CsmsVendor $vendor): array
    {
        CsmsPortal::role($r->user(), 'vendor');
        CsmsPortal::authorize($r->user(), $vendor);
        $d = $r->validate(['upload' => 'required|uuid', 'index' => 'required|integer|min:0|max:179', 'total' => 'required|integer|min:1|max:180', 'data' => 'required|string|max:150000', 'question_id' => 'required|exists:csms_questions,id']);
        abort_if($d['index'] >= $d['total'], 422);
        $root = storage_path('app/private/csms-uploads/'.$r->user()->id);
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        foreach (glob($root.'/*') ?: [] as $old) {
            if (is_dir($old) && filemtime($old) < time() - 3600) {
                foreach (glob($old.'/*') ?: [] as $f) {
                    unlink($f);
                }rmdir($old);
            }
        }
        $size = 0;
        foreach (glob($root.'/*/*') ?: [] as $f) {
            $size += filesize($f);
        }abort_if($size > 100 * 1024 * 1024, 413);
        $dir = $root.'/'.$d['upload'];
        if (! is_dir($dir)) {
            abort_if(count(glob($root.'/*') ?: []) >= 4, 429);
            mkdir($dir, 0700);
        }
        $scope = hash('sha256', implode('|', [$r->user()->id, $r->user()->auth_version, $vendor->id, $d['question_id'], $d['total']]));
        $meta = $dir.'/scope';
        if (! file_exists($meta)) {
            file_put_contents($meta, $scope, LOCK_EX);
        }abort_unless(hash_equals(file_get_contents($meta), $scope), 403);
        file_put_contents($dir.'/'.$d['index'], $d['data'], LOCK_EX);
        if ($d['index'] !== $d['total'] - 1) {
            return ['received' => $d['index']];
        }
        $temp = null;
        try {
            $json = '';
            for ($i = 0; $i < $d['total']; $i++) {
                abort_unless(file_exists($dir.'/'.$i), 422);
                $json .= file_get_contents($dir.'/'.$i);
                abort_if(strlen($json) > 23000000, 413);
            }
            $body = json_decode($json, true);
            abort_unless(is_array($body), 422);
            $v = Validator::make($body, ['name' => 'required|string|max:240', 'base64' => 'required|string|max:22000000'])->validate();
            $bytes = base64_decode($v['base64'], true);
            abort_unless($bytes !== false && strlen($bytes) > 0 && strlen($bytes) <= 15 * 1024 * 1024, 422, 'Ukuran atau isi bukti tidak sesuai.');
            $temp = tempnam(sys_get_temp_dir(), 'csms-');
            file_put_contents($temp, $bytes);
            $file = new UploadedFile($temp, basename(str_replace('\\', '/', $v['name'])), null, null, true);
            $uploadRequest = Request::create('/', 'POST', ['question_id' => $d['question_id']], [], ['file' => $file]);
            $uploadRequest->setUserResolver($r->getUserResolver());

            return $this->upload($uploadRequest, $vendor);
        } finally {
            if ($temp && file_exists($temp)) {
                unlink($temp);
            }foreach (glob($dir.'/*') ?: [] as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }

    public function roles(Request $r, CsmsAccount $user): array
    {
        CsmsPortal::role($r->user(), 'admin');
        abort_if($user->hasRole('vendor') || $user->hasRole('admin') || $user->id === $r->user()->id, 403);
        $d = $r->validate(['role' => 'required|array|min:1', 'role.*' => ['required', Rule::in(['verifikator', 'validator', 'pengadaan'])]]);
        $user->forceFill(['roles' => array_values(array_unique($d['role'])), 'auth_version' => $user->auth_version + 1])->save();
        CsmsPortal::audit('account_roles_changed', null, ['user_id' => $user->id]);

        return $user->publicData();
    }
}
