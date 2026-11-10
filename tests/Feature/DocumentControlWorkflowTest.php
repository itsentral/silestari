<?php

use App\Models\ControlledDocument;
use App\Models\ProcedureRevision;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

function dcPeople(): array
{
    $maker = User::factory()->admin()->create(['name' => 'Pembuat', 'password' => 'WorkflowPass123']);
    $reviewer = User::factory()->departmentUser()->create(['name' => 'Reviewer', 'department' => 'HSE', 'allowed_modules' => ['documents'], 'password' => 'WorkflowPass123']);
    $approver = User::factory()->departmentUser()->create(['name' => 'Penyetuju', 'department' => 'HSE', 'allowed_modules' => ['documents'], 'password' => 'WorkflowPass123']);

    return [$maker, $reviewer, $approver];
}
function dcPayload(array $people): array
{
    [$maker,$reviewer,$approver] = $people;

    return ['number' => 'SOP-HSE-001', 'department' => 'HSE', 'title' => 'Prosedur Pengelolaan K3L', 'process_group' => 'Pendukung', 'reviewer_id' => $reviewer->id, 'approver_id' => $approver->id, 'publisher_id' => $maker->id, 'sections' => array_fill_keys(array_keys(ProcedureRevision::SECTIONS), 'Isi prosedur lengkap'), 'change_reason' => 'Pembuatan prosedur baru'];
}
function dcSign(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFAAAAAeCAYAAAC7Q5mxAAAAU0lEQVR4nO3QMQ0AIQDAQCQg4SXg3xx4eAbS5IbuzY25vq3/jdcD9QACBJgOIECA6QACBJgOIECA6QACBJgOIECA6QACBJgOIECA6QACBJgO4GUHgZnbVaRsq50AAAAASUVORK5CYII=';
}
function dcAction($test, User $actor, ControlledDocument $doc, string $action, array $extra = [])
{
    $doc->refresh();
    $r = $doc->currentRevision()->first();

    return $test->actingAs($actor)->withSession(['auth_version' => $actor->auth_version])->post(route('document-control.action', $doc), ['action' => $action, 'lock_version' => $r->lock_version, 'password' => 'WorkflowPass123', 'signature' => dcSign(), 'reason' => 'Catatan tindakan untuk penelusuran', ...$extra]);
}
function dcCreate($test, array $people, array $extra = []): ControlledDocument
{
    $test->actingAs($people[0])->withSession(['auth_version' => 1])->post(route('document-control.store'), [...dcPayload($people), ...$extra])->assertSessionHasNoErrors()->assertRedirect();

    return ControlledDocument::firstOrFail();
}
function dcPublish($test, array $people, ControlledDocument $doc): void
{
    foreach ([[$people[0], 'submit'], [$people[1], 'review'], [$people[2], 'approve'], [$people[0], 'publish']] as [$actor,$action]) {
        dcAction($test, $actor, $doc, $action)->assertSessionHasNoErrors()->assertRedirect();
    }
}

test('creates all eight procedure sections in Laravel and prevents status or creator spoofing', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p, ['status' => 'published', 'creator_id' => $p[1]->id]);
    $r = $doc->currentRevision;
    expect($doc->creator_id)->toBe($p[0]->id);
    expect($r->status)->toBe('draft');
    expect($r->sections)->toHaveCount(8);
    expect($r->content_hash)->toHaveLength(64);
    expect(DB::table('procedure_revisions')->where('id', $r->id)->value('sections'))->not->toContain('Isi prosedur lengkap');
    $this->get(route('document-control.show', $doc))->assertOk()->assertSee('TTD &amp; Ajukan Review', false)->assertSee('Tujuan')->assertSee('Dokumen Terkait');
    $this->get(route('document-control.edit', $doc))->assertOk();
});
test('requires signatures and each assigned account before publication and links the published register', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    dcAction($this, $p[0], $doc, 'publish')->assertForbidden();
    dcAction($this, $p[0], $doc, 'submit', ['signature' => ''])->assertSessionHasErrors('signature');
    dcAction($this, $p[0], $doc, 'submit')->assertRedirect();
    dcAction($this, $p[0], $doc, 'review')->assertForbidden();
    dcAction($this, $p[1], $doc, 'review')->assertRedirect();
    dcAction($this, $p[2], $doc, 'approve')->assertRedirect();
    dcAction($this, $p[0], $doc, 'publish', ['password' => 'Wrong'])->assertSessionHasErrors('password');
    dcAction($this, $p[0], $doc, 'publish')->assertRedirect();
    $doc->refresh();
    $r = $doc->currentRevision;
    expect($r->status)->toBe('published');
    expect($doc->published_revision_id)->toBe($r->id);
    expect($r->events()->whereNotNull('signature')->count())->toBe(4);
    $native = collect(Workspace::where('source', 'iso')->first()->payload['records']['documents'])->firstWhere('nativeProcedureId', $doc->id);
    expect($native['number'])->toBe('SOP-HSE-001');
    expect($native['revision'])->toBe('1');
    expect($native['status'])->toBe('Berlaku');
    $this->get(route('document-control.edit', $doc))->assertForbidden();
    $this->get(route('document-control.published', $doc))->assertRedirect();
});
test('rejection requires a reason and sends the procedure back to its maker for a new review cycle', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    dcAction($this, $p[0], $doc, 'submit')->assertRedirect();
    dcAction($this, $p[1], $doc, 'reject', ['reason' => ''])->assertSessionHasErrors('reason');
    dcAction($this, $p[1], $doc, 'reject', ['reason' => 'Tujuan belum menjelaskan batas proses'])->assertRedirect();
    $doc->refresh();
    expect($doc->currentRevision->status)->toBe('correction');
    $this->actingAs($p[0])->withSession(['auth_version' => 1])->get(route('document-control.edit', $doc))->assertOk();
    $data = dcPayload($p);
    $data['sections']['purpose'] = 'Tujuan telah diperbaiki';
    $data['change_reason'] = 'Memperjelas batas proses sesuai catatan reviewer';
    $data['lock_version'] = $doc->currentRevision->lock_version;
    $this->put(route('document-control.update', $doc), $data)->assertSessionHasNoErrors()->assertRedirect();
    dcAction($this, $p[0], $doc, 'submit')->assertRedirect();
    $doc->refresh();
    expect($doc->currentRevision->cycle)->toBe(2);
    expect($doc->events()->where('action', 'reject')->sole()->reason)->toBe('Tujuan belum menjelaskan batas proses');
    dcAction($this, $p[0], $doc, 'publish')->assertForbidden();
    dcAction($this, $p[1], $doc, 'review')->assertRedirect();
    dcAction($this, $p[2], $doc, 'approve')->assertRedirect();
    dcAction($this, $p[0], $doc, 'publish')->assertRedirect();
});
test('published content is immutable and a new revision retains the old published version until approved', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    dcPublish($this, $p, $doc);
    $doc->refresh();
    $old = $doc->publishedRevision;
    $oldHash = $old->content_hash;
    $this->actingAs($p[0])->withSession(['auth_version' => 1])->put(route('document-control.update', $doc), [...dcPayload($p), 'lock_version' => $old->lock_version])->assertForbidden();
    dcAction($this, $p[0], $doc, 'revise', ['reason' => 'Perubahan kewajiban proses terbaru'])->assertRedirect();
    $doc->refresh();
    expect($doc->currentRevision->version)->toBe(2);
    expect($doc->currentRevision->status)->toBe('revision');
    expect($doc->published_revision_id)->toBe($old->id);
    expect($old->fresh()->content_hash)->toBe($oldHash);
    expect($doc->currentRevision->events()->whereNotNull('signature')->count())->toBe(0);
    $data = dcPayload($p);
    $data['sections']['details'] = 'Rincian baru untuk revisi kedua';
    $data['change_reason'] = 'Perubahan rincian sesuai evaluasi';
    $data['lock_version'] = $doc->currentRevision->lock_version;
    $this->put(route('document-control.update', $doc), $data)->assertRedirect();
    dcPublish($this, $p, $doc);
    $doc->refresh();
    expect($doc->publishedRevision->version)->toBe(2);
    expect($old->fresh()->sections['details'])->toBe('Isi prosedur lengkap');
    $rows = Workspace::where('source', 'iso')->first()->payload['records']['documents'];
    expect(collect($rows)->where('nativeProcedureId', $doc->id))->toHaveCount(1);
    expect(collect($rows)->firstWhere('nativeProcedureId', $doc->id)['revision'])->toBe('2');
});
test('blocks unauthorized departments actions and direct URLs even for unassigned administrators', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    $outsider = User::factory()->departmentUser()->create(['department' => 'Finance', 'allowed_modules' => ['documents'], 'password' => 'WorkflowPass123']);
    $this->actingAs($outsider)->withSession(['auth_version' => 1])->get(route('document-control.show', $doc))->assertForbidden();
    $this->get(route('document-control.index', ['status' => 'all']))->assertOk()->assertDontSee('SOP-HSE-001');
    $none = User::factory()->departmentUser()->create(['allowed_modules' => []]);
    $this->actingAs($none)->withSession(['auth_version' => 1])->get(route('document-control.index'))->assertForbidden();
    $this->get('/dashboard?view=documents')->assertForbidden();
    dcAction($this, $p[0], $doc, 'submit')->assertRedirect();
    $other = User::factory()->admin()->create(['password' => 'WorkflowPass123']);
    dcAction($this, $other, $doc, 'review')->assertForbidden();
});
test('rejects incomplete procedures stale writes and invalid signature images', function (): void {
    $p = dcPeople();
    $payload = dcPayload($p);
    $payload['sections']['details'] = '';
    $doc = dcCreate($this, $p, $payload);
    dcAction($this, $p[0], $doc, 'submit')->assertSessionHasErrors('sections');
    dcAction($this, $p[0], $doc, 'submit', ['signature' => 'data:image/png;base64,'.base64_encode('not an image')])->assertSessionHasErrors('signature');
    $r = $doc->currentRevision;
    $this->actingAs($p[0])->withSession(['auth_version' => 1])->put(route('document-control.update', $doc), [...dcPayload($p), 'lock_version' => $r->lock_version + 1])->assertConflict();
    expect($r->fresh()->sections['details'])->toBe('');
});
test('protects published register metadata against edits through the legacy workspace API', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    dcPublish($this, $p, $doc);
    $w = Workspace::where('source', 'iso')->first();
    $payload = $w->payload;
    $payload['records']['documents'][0]['revision'] = '99';
    $this->actingAs($p[0])->withSession(['auth_version' => 1])->postJson('/workspace/iso', ['revision' => $w->revision, 'payload' => $payload])->assertUnprocessable();
    expect($w->fresh()->payload['records']['documents'][0]['revision'])->toBe('1');
});
test('preserves historical snapshots and signatures without ever storing the account password', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    dcPublish($this, $p, $doc);
    $events = $doc->events()->get();
    expect($events)->toHaveCount(5);
    foreach ($events as $event) {
        expect(json_encode($event->snapshot))->not->toContain('WorkflowPass123');
        expect($event->toArray())->not->toHaveKey('signature')->not->toHaveKey('snapshot');
    }expect(DB::table('procedure_events')->whereNotNull('signature')->value('signature'))->not->toBe(dcSign());
    $this->get(route('document-control.show', $doc))->assertOk()->assertSee('Riwayat perubahan &amp; keputusan', false)->assertSee('Identitas isi:');
});

test('rejects self review assignments and accounts that no longer have document access', function (): void {
    $p = dcPeople();
    $this->actingAs($p[0])->withSession(['auth_version' => 1])->post(route('document-control.store'), [...dcPayload($p), 'reviewer_id' => $p[0]->id])->assertSessionHasErrors('reviewer_id');
    $p[1]->forceFill(['is_active' => false])->save();
    $this->post(route('document-control.store'), dcPayload($p))->assertSessionHasErrors('reviewer_id');
    expect(ControlledDocument::count())->toBe(0);
});

test('cannot publish a forged approved state without three matching signed decisions', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    $doc->currentRevision->forceFill(['status' => 'approved'])->save();
    dcAction($this, $p[0], $doc, 'publish')->assertConflict();
    expect($doc->fresh()->published_revision_id)->toBeNull();
    expect($doc->events()->where('action', 'publish')->count())->toBe(0);
});

test('returns an approver rejection to the maker and rejects replayed signing requests', function (): void {
    $p = dcPeople();
    $doc = dcCreate($this, $p);
    $oldLock = $doc->currentRevision->lock_version;
    dcAction($this,$p[0],$doc,'submit')->assertRedirect();
    dcAction($this,$p[0],$doc,'submit',['lock_version' => $oldLock])->assertConflict();
    dcAction($this,$p[1],$doc,'review')->assertRedirect();
    dcAction($this,$p[2],$doc,'reject',['reason' => 'Referensi belum sesuai dengan proses yang disahkan'])->assertRedirect();
    expect($doc->fresh()->currentRevision->status)->toBe('correction');
    $this->actingAs($p[0])->withSession(['auth_version' => 1])->get(route('document-control.edit',$doc))->assertOk();
});
