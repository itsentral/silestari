<?php

use App\Models\CommunicationEvent;
use App\Models\CommunicationEvidence;
use App\Models\CommunicationMatrix;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function commUser(): User
{
    return User::factory()->departmentUser()->create(['name' => 'Petugas HSE', 'department' => 'HSE', 'allowed_modules' => ['communication'], 'password' => 'CommunicationPass123']);
}
function commLogin($test, User $actor): void
{
    $test->actingAs($actor)->withSession(['auth_version' => $actor->auth_version]);
}
function commCreate($test, User $actor, bool $template = true): CommunicationMatrix
{
    commLogin($test, $actor);
    $test->post(route('communication.store'), ['year' => 2026, 'department' => 'HSE', 'template' => $template])->assertSessionHasNoErrors()->assertRedirect();

    return CommunicationMatrix::firstOrFail();
}
function commAct($test, CommunicationMatrix $matrix, string $action, array $data = [])
{
    return $test->postJson(route('communication.action', $matrix), ['action' => $action, 'revision' => $matrix->fresh()->revision, ...$data]);
}
function commImage(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFAAAAAeCAYAAAC7Q5mxAAAAU0lEQVR4nO3QMQ0AIQDAQCQg4SXg3xx4eAbS5IbuzY25vq3/jdcD9QACBJgOIECA6QACBJgOIECA6QACBJgOIECA6QACBJgOIECA6QACBJgOIECA6QACBJgO4GUHgZnbVaRsq50AAAAASUVORK5CYII=';
}
function commUpload($test, CommunicationMatrix $matrix, string $row, array $data = [])
{
    return $test->postJson(route('communication.chunks', $matrix), ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'revision' => $matrix->fresh()->revision, 'material_id' => $row, 'data' => json_encode(['file_name' => 'Bukti rapat.png', 'file_data' => commImage(), ...$data])]);
}
test('communication template matches eleven columns with editable sources and materials', function (): void {
    $u = commUser();
    $m = commCreate($this, $u);
    $p = $m->payload;
    expect($p['sources'])->toHaveCount(18);
    expect($p['materials'])->toHaveCount(33);
    expect(collect($p['sources'])->first()['name'])->toBe('Kebijakan Perusahaan');
    expect($m->signatures)->toBe([]);
    $this->get(route('communication.show', $m))->assertOk()->assertSee('Dokumen pengendalian')->assertSee('Evaluasi efektivitas')->assertSee('Bahasa yang digunakan')->assertSee('Salin ke 2027');
    $this->get(route('communication.index'))->assertOk()->assertSee('Catatan komunikasi lama');
    expect(DB::table('communication_matrices')->where('id', $m->id)->value('payload'))->not->toContain('Kebijakan Perusahaan');
});
test('communication supports adding editing and deleting sources and materials with scoped ids', function (): void {
    $u = commUser();
    $m = commCreate($this, $u, false);
    commAct($this, $m, 'add_source', ['name' => 'Konsultasi pekerja', 'source_id' => ''])->assertOk();
    $s = $m->fresh()->payload['sources'][0];
    commAct($this, $m, 'update_source', ['source_id' => $s['id'], 'name' => 'Masukan pekerja'])->assertOk();
    $row = ['source_id' => $s['id'], 'title' => 'Pertemuan K3', 'criteria' => 'Internal', 'method' => 'Pertemuan', 'language' => 'Indonesia', 'timing' => 'Setiap bulan', 'audience' => 'Pekerja', 'responsible' => 'HSE', 'effectiveness' => 'Efektif', 'control_document' => 'Notulen'];
    commAct($this, $m, 'add_material', [...$row, 'material_id' => ''])->assertOk();
    $material = $m->fresh()->payload['materials'][0];
    commAct($this, $m, 'update_material', [...$row, 'material_id' => $material['id'], 'title' => 'Konsultasi K3'])->assertOk();
    expect($m->fresh()->payload['materials'][0]['title'])->toBe('Konsultasi K3');
    commAct($this, $m, 'delete_material', ['material_id' => (string) Str::uuid()])->assertNotFound();
    commAct($this, $m, 'delete_material', ['material_id' => $material['id']])->assertOk();
    expect($m->fresh()->payload['materials'])->toBe([]);
    commAct($this, $m, 'delete_source', ['source_id' => $s['id']])->assertOk();
    expect($m->fresh()->payload['sources'])->toBe([]);
    expect(CommunicationEvent::count())->toBe(7);
});
test('communication evidence is private content checked and removed with its material', function (): void {
    Storage::fake('local');
    $u = commUser();
    $m = commCreate($this, $u);
    $row = $m->payload['materials'][0]['id'];
    commUpload($this, $m, $row)->assertOk();
    $file = CommunicationEvidence::firstOrFail();
    Storage::disk('local')->assertExists($file->path);
    $this->get(route('communication.file', [$m, $file]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    commUpload($this, $m, $row, ['file_name' => 'forged.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode('<script>bad</script>')])->assertUnprocessable()->assertJsonValidationErrors('file_data');
    commUpload($this, $m, (string) Str::uuid())->assertNotFound();
    expect(CommunicationEvidence::count())->toBe(1);
    commAct($this, $m, 'delete_material', ['material_id' => $row])->assertOk();
    expect(CommunicationEvidence::count())->toBe(0);
    $this->get(route('communication.file', [$m, $file]))->assertNotFound();
});
test('copy creates independent next year plans without evidence results or signatures and never overwrites', function (): void {
    Storage::fake('local');
    $u = commUser();
    $m = commCreate($this, $u);
    $row = $m->payload['materials'][0];
    commAct($this, $m, 'update_material', [...$row, 'material_id' => $row['id'], 'effectiveness' => 'Efektif terlaksana', 'control_document' => 'Notulen kegiatan'])->assertOk();
    commUpload($this, $m, $row['id'])->assertOk();
    commAct($this, $m, 'sign', ['slot' => 'prepared', 'password' => 'CommunicationPass123', 'signature' => commImage()])->assertOk();
    $old = $m->fresh();
    $oldRevision = $old->revision;
    commAct($this, $m, 'copy')->assertOk();
    $copy = CommunicationMatrix::where('year', 2027)->firstOrFail();
    expect($copy->copied_from_id)->toBe($m->id);
    expect($copy->payload['sources'])->toHaveCount(18);
    expect($copy->payload['materials'])->toHaveCount(33);
    expect($copy->payload['materials'][0]['id'])->not->toBe($row['id']);
    expect($copy->payload['materials'][0]['effectiveness'])->toBe('');
    expect($copy->payload['materials'][0]['control_document'])->toBe('');
    expect($copy->signatures)->toBe([]);
    expect($copy->evidence()->count())->toBe(0);
    expect($old->evidence()->count())->toBe(1);
    expect($m->fresh()->revision)->toBe($oldRevision);
    expect($m->fresh()->signatures)->toHaveKey('prepared');
    commAct($this, $m, 'copy')->assertConflict();
    expect(CommunicationMatrix::count())->toBe(2);
});
test('signatures require assigned account own password valid image and are cleared on content change', function (): void {
    $u = commUser();
    $reviewer = commUser();
    $m = commCreate($this, $u);
    commAct($this, $m, 'metadata', ['prepared' => $u->id, 'reviewed' => $reviewer->id, 'approved' => $reviewer->id, 'number' => 'FR-HSE-04', 'document_revision' => '01', 'edition' => 'Oktober 2026'])->assertOk();
    commAct($this, $m, 'sign', ['slot' => 'reviewed', 'password' => 'CommunicationPass123', 'signature' => commImage()])->assertForbidden();
    commAct($this, $m, 'sign', ['slot' => 'prepared', 'password' => 'wrong', 'signature' => commImage()])->assertUnprocessable()->assertJsonValidationErrors('password');
    commAct($this, $m, 'sign', ['slot' => 'prepared', 'password' => 'CommunicationPass123', 'signature' => 'data:image/png;base64,AAAA'])->assertUnprocessable()->assertJsonValidationErrors('signature');
    commAct($this, $m, 'sign', ['slot' => 'prepared', 'password' => 'CommunicationPass123', 'signature' => commImage()])->assertOk();
    commLogin($this, $reviewer);
    commAct($this, $m, 'sign', ['slot' => 'reviewed', 'password' => 'CommunicationPass123', 'signature' => commImage()])->assertOk();
    expect($m->fresh()->signatures)->toHaveKeys(['prepared', 'reviewed']);
    $this->get(route('communication.show', $m))->assertOk()->assertSee('Petugas HSE');
    expect(DB::table('communication_matrices')->value('signatures'))->not->toContain('data:image/png');
    foreach (CommunicationEvent::all() as $event) {
        expect(json_encode($event->snapshot))->not->toContain('CommunicationPass123');
    }
    commAct($this, $m, 'add_source', ['name' => 'Sumber baru'])->assertOk();
    expect($m->fresh()->signatures)->toBe([]);
});
test('communication denies foreign departments disabled modules and unassigned foreign signers', function (): void {
    $u = commUser();
    $m = commCreate($this, $u);
    $outsider = User::factory()->departmentUser()->create(['department' => 'HRGA', 'allowed_modules' => ['communication']]);
    commAct($this, $m, 'metadata', ['prepared' => $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('prepared');
    commLogin($this, $outsider);
    $this->get(route('communication.show', $m))->assertForbidden();
    commAct($this, $m, 'add_source', ['name' => 'Tidak boleh'])->assertForbidden();
    $this->post(route('communication.store'), ['year' => 2027, 'department' => 'HSE', 'template' => false])->assertForbidden();
    $denied = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['company']]);
    commLogin($this, $denied);
    $this->get(route('communication.index'))->assertForbidden();
    $this->get(route('communication.show', $m))->assertForbidden();
});
test('communication rejects stale changes invalid rows and cross matrix file ids', function (): void {
    Storage::fake('local');
    $u = commUser();
    $m = commCreate($this, $u);
    $revision = $m->revision;
    commAct($this, $m, 'add_source', ['name' => 'Satu'])->assertOk();
    commAct($this, $m, 'add_source', ['revision' => $revision, 'name' => 'Dua'])->assertConflict();
    commAct($this, $m, 'add_material', ['source_id' => (string) Str::uuid(), 'title' => 'Topik', 'criteria' => 'Internal'])->assertNotFound();
    commAct($this, $m, 'add_material', ['source_id' => $m->payload['sources'][0]['id'], 'title' => 'Topik', 'criteria' => 'Lainnya'])->assertUnprocessable()->assertJsonValidationErrors('criteria');
    commUpload($this, $m, $m->payload['materials'][0]['id'])->assertOk();
    $file = CommunicationEvidence::firstOrFail();
    commAct($this, $m, 'copy')->assertOk();
    $other = CommunicationMatrix::where('year', 2027)->firstOrFail();
    $this->get(route('communication.file', [$other, $file]))->assertNotFound();
    commAct($this, $other, 'delete_evidence', ['evidence_id' => $file->id])->assertNotFound();
    expect($m->fresh()->payload['sources'])->toHaveCount(19);
});
test('communication scope bound chunk upload assembles larger files and clears signatures', function (): void {
    Storage::fake('local');
    $u = commUser();
    $m = commCreate($this, $u);
    $row = $m->payload['materials'][0]['id'];
    commAct($this, $m, 'sign', ['slot' => 'prepared', 'password' => 'CommunicationPass123', 'signature' => commImage()])->assertOk();
    $json = json_encode(['file_name' => 'notulen.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode("%PDF-1.4\n".str_repeat('Bukti pelaksanaan komunikasi ', 25000)."\n%%EOF")]);
    $parts = str_split($json, 130000);
    $uuid = (string) Str::uuid();
    $revision = $m->fresh()->revision;
    foreach ($parts as $i => $part) {
        $this->postJson(route('communication.chunks', $m), ['upload' => $uuid, 'index' => $i, 'total' => count($parts), 'data' => $part, 'revision' => $revision, 'material_id' => $row])->assertOk();
    }
    expect(CommunicationEvidence::count())->toBe(1);
    expect($m->fresh()->signatures)->toBe([]);
    Storage::disk('local')->assertExists(CommunicationEvidence::first()->path);
});
test('communication routes preserve old register and escape user text', function (): void {
    $u = commUser();
    $m = commCreate($this, $u, false);
    commAct($this, $m, 'add_source', ['name' => '<script>alert(1)</script>'])->assertOk();
    $this->get(route('communication.show', $m))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $this->get('/dashboard?view=communication')->assertRedirect(route('communication.index'));
    $this->get('/modules/communication')->assertRedirect(route('communication.index'));
    $this->get('/dashboard?view=communication&legacy=1')->assertOk();
    $this->post(route('communication.store'), ['year' => 2026, 'department' => 'HSE', 'template' => true])->assertConflict();
});
