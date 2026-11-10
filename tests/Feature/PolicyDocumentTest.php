<?php

use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\PolicyDocumentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function policyPayload(string $summary = 'Kebijakan awal'): array
{
    return ['title' => 'Kebijakan Perusahaan', 'file_name' => 'kebijakan.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"), 'change_summary' => $summary];
}

beforeEach(function (): void {
    Storage::fake('local');
});

test('keeps each policy revision privately with uploader and change history', function (): void {
    $user = User::factory()->admin()->create();
    $service = app(PolicyDocumentService::class);
    $first = $service->save($user, 0, policyPayload());
    $second = $service->save($user, 1, policyPayload('Revisi komitmen perusahaan'));
    expect($first['version'])->toBe(1);
    expect($second['version'])->toBe(2);
    $docs = PolicyDocument::orderBy('version')->get();
    expect($docs)->toHaveCount(2);
    foreach ($docs as $doc) {
        Storage::disk('local')->assertExists($doc->path);
        expect($doc->uploaded_by)->toBe($user->id);
        expect($doc->uploaded_by_name)->toBe($user->name);
        expect($doc->toArray())->not->toHaveKey('path');
    }
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/policy')->assertOk()->assertSee('Revisi komitmen perusahaan')->assertSee('Riwayat perubahan')->assertSee('Simpan versi baru')->assertDontSee('Naskah kebijakan');
    $this->get('/policy?version=1')->assertOk()->assertSee('Riwayat versi sebelumnya');
    $this->get('/policy/'.$docs[0]->id.'/file')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('does not expose policy metadata or files without independent module permission', function (): void {
    $admin = User::factory()->admin()->create();
    app(PolicyDocumentService::class)->save($admin, 0, policyPayload());
    $doc = PolicyDocument::first();
    $worker = User::factory()->departmentUser()->create(['allowed_modules' => ['targets']]);
    $this->actingAs($worker)->withSession(['auth_version' => 1]);
    $this->get('/policy')->assertForbidden();
    $this->get('/policy/'.$doc->id.'/file')->assertForbidden();
    $this->get('/dashboard?view=policy')->assertForbidden();
    $this->postJson('/workspace/policy/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'data' => '{}'])->assertForbidden();
});

test('allows a permitted worker to view the policy and uploads through authenticated chunks', function (): void {
    $user = User::factory()->departmentUser()->create(['allowed_modules' => ['policy']]);
    $body = json_encode(['revision' => 0, 'payload' => policyPayload()]);
    $this->actingAs($user)->withSession(['auth_version' => 1])->postJson('/workspace/policy/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'data' => $body])->assertOk()->assertJsonPath('version', 1);
    $this->get('/policy')->assertOk();
    $this->get('/modules/policy')->assertRedirect('/policy');
    $this->get('/dashboard?view=policy')->assertRedirect('/policy');
});

test('rejects a disguised executable without creating a file or revision', function (): void {
    $user = User::factory()->admin()->create();
    $body = policyPayload();
    $body['file_data'] = 'data:application/pdf;base64,'.base64_encode('<?php echo "unsafe";');
    $this->actingAs($user)->withSession(['auth_version' => 1])->postJson('/workspace/policy/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'data' => json_encode(['revision' => 0, 'payload' => $body])])->assertUnprocessable();
    expect(PolicyDocument::count())->toBe(0);
    expect(Storage::disk('local')->allFiles('policy-documents'))->toBe([]);
});

test('rejects stale policy versions while retaining the prior document', function (): void {
    $user = User::factory()->admin()->create();
    app(PolicyDocumentService::class)->save($user, 0, policyPayload());
    $this->actingAs($user)->withSession(['auth_version' => 1])->postJson('/workspace/policy/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'data' => json_encode(['revision' => 0, 'payload' => policyPayload('Stale')])])->assertConflict();
    expect(PolicyDocument::count())->toBe(1);
    expect(Storage::disk('local')->allFiles('policy-documents'))->toHaveCount(1);
});

test('serves responsive styles and secure random ID compatibility for local WiFi access', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/dashboard')->assertOk()->assertSee('/css/mobile.css', false)->assertSee('/js/browser-compat.js', false)->assertSee('/js/mobile-navigation.js', false);
    $this->get('/policy')->assertOk()->assertSee('/css/mobile.css', false)->assertSee('maksimal 10 MB')->assertDontSee('Naskah kebijakan');
});

test('removes deleted policies from display and history while new uploads remain listed', function (): void {
    $user = User::factory()->admin()->create();
    $service = app(PolicyDocumentService::class);
    $service->save($user, 0, policyPayload());
    $service->save($user, 1, policyPayload('Versi kedua'));
    $latest = PolicyDocument::orderByDesc('version')->first();
    $this->actingAs($user)->withSession(['auth_version' => 1])->get('/policy')->assertOk()->assertSee('Hapus');
    $this->delete('/policy/'.$latest->id)->assertRedirect('/policy');
    expect(PolicyDocument::find($latest->id))->toBeNull();
    $deleted = PolicyDocument::withTrashed()->findOrFail($latest->id);
    expect($deleted->deleted_by)->toBe($user->id);
    expect($deleted->deleted_by_name)->toBe($user->name);
    Storage::disk('local')->assertExists($deleted->path);
    $this->get('/policy')->assertOk()->assertSee('Belum ada lampiran kebijakan')->assertDontSee('Versi kedua')->assertDontSee('Dihapus oleh')->assertSee('data-version="2"', false);
    $this->get('/policy/'.$latest->id.'/file')->assertNotFound();
    $result = $service->save($user, 2, policyPayload('Pengganti'));
    expect($result['version'])->toBe(3);
    expect(PolicyDocument::withTrashed()->count())->toBe(3);
    $this->get('/policy')->assertOk()->assertSee('Pengganti')->assertDontSee('Versi kedua')->assertViewHas('history', fn ($history): bool => $history->total() === 2);
});

test('does not allow an account without policy permission to delete a document', function (): void {
    $admin = User::factory()->admin()->create();
    app(PolicyDocumentService::class)->save($admin, 0, policyPayload());
    $doc = PolicyDocument::first();
    $worker = User::factory()->departmentUser()->create(['allowed_modules' => ['targets']]);
    $this->actingAs($worker)->withSession(['auth_version' => 1])->delete('/policy/'.$doc->id)->assertForbidden();
    expect($doc->fresh()->deleted_at)->toBeNull();
});

test('uses the company profile shell and only shows permitted navigation for a policy user', function (): void {
    $user = User::factory()->departmentUser()->create(['allowed_modules' => ['policy']]);
    $response = $this->actingAs($user)->withSession(['auth_version' => 1])->get('/policy')->assertOk()->assertSee('SISTEM MANAJEMEN TERPADU')->assertSee('/css/workspace.css', false)->assertSee('aria-label="Menu utama SILESTARI"', false)->assertSee('aria-current="page"', false);
    $response->assertDontSee('data-workspace-url="http://localhost/dashboard?module=risk"', false)->assertDontSee('data-workspace-url="http://localhost/dashboard?module=audit"', false)->assertDontSee('Akun &amp; Hak Akses', false);
    $this->get('/modules/risk')->assertForbidden();
    $this->get('/modules/audit')->assertForbidden();
});
