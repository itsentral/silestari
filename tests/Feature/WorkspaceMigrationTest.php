<?php

use App\Models\MigrationBackup;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

function workspaceAdmin(): User
{
    return User::factory()->admin()->create();
}
test('hydrates old application with encrypted mysql workspace data and no browser credentials', function (): void {
    $u = workspaceAdmin();
    MigrationBackup::create(['source' => 'iso', 'filename' => 'test.json', 'checksum' => str_repeat('a', 64), 'record_count' => 1, 'record_counts' => ['targets' => 1], 'payload' => ['version' => 1, 'company' => ['name' => 'Preserved Company'], 'records' => ['targets' => [['id' => 'T1', 'target' => '10', 'm1' => '12']]]]]);
    $this->actingAs($u)->withSession(['auth_version' => 1])->get('/dashboard')->assertOk()->assertSee('Preserved Company')->assertSee('/js/workspace-bridge.js', false)->assertDontSee('legacy_password_hash');
    expect(Workspace::where('source', 'iso')->sole()->payload['records']['targets'][0]['m1'])->toBe('12');
    expect(DB::table('workspaces')->where('source', 'iso')->value('payload'))->not->toContain('Preserved Company');
});
test('commits edits and deletions with revision history and rejects stale changes', function (): void {
    $u = workspaceAdmin();
    $service = app(WorkspaceService::class);
    $w = $service->workspace('iso');
    $data = $w->payload;
    $data['records']['targets'] = [['id' => 'target', 'title' => 'New target']];
    $this->actingAs($u)->withSession(['auth_version' => 1])->postJson('/workspace/iso', ['revision' => 1, 'payload' => $data])->assertOk()->assertJsonPath('revision', 2);
    $this->postJson('/workspace/iso', ['revision' => 1, 'payload' => $w->payload])->assertConflict();
    $data['records']['targets'] = [];
    $this->postJson('/workspace/iso', ['revision' => 2, 'payload' => $data])->assertOk();
    expect($w->fresh()->payload['records']['targets'])->toBe([]);
    expect($w->fresh()->history)->toHaveCount(2);
});
test('filters every unauthorized module and keeps foreign department records when a worker saves', function (): void {
    $u = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['targets']]);
    $w = app(WorkspaceService::class)->workspace('iso');
    $w->payload = ['company' => [], 'records' => ['targets' => [['id' => 'own', 'accessDepartment' => 'HSE', 'title' => 'Own'], ['id' => 'foreign', 'accessDepartment' => 'Finance', 'title' => 'Secret Finance']], 'legal' => [['id' => 'law', 'dept' => 'HSE', 'title' => 'Secret legal']]]];
    $w->save();
    $this->actingAs($u)->withSession(['auth_version' => 1])->getJson('/workspace')->assertOk()->assertJsonCount(1, 'iso.payload.records.targets')->assertJsonCount(0, 'iso.payload.records.legal')->assertDontSee('Secret Finance')->assertDontSee('Secret legal');
    $data = app(WorkspaceService::class)->scoped($u, 'iso', $w->payload);
    $data['records']['targets'] = [];
    $data['records']['legal'] = [['id' => 'hacked', 'accessDepartment' => 'HSE']];
    $this->postJson('/workspace/iso', ['revision' => 1, 'payload' => $data])->assertOk();
    expect(array_column($w->fresh()->payload['records']['targets'], 'id'))->toBe(['foreign']);
    expect($w->fresh()->payload['records']['legal'][0]['id'])->toBe('law');
});
test('rejects cross department IDs and forged ownership', function (): void {
    $u = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['targets']]);
    $w = app(WorkspaceService::class)->workspace('iso');
    $w->payload = ['records' => ['targets' => [['id' => 'foreign', 'accessDepartment' => 'Finance']]]];
    $w->save();
    $this->actingAs($u)->withSession(['auth_version' => 1]);
    foreach ([['id' => 'foreign', 'accessDepartment' => 'HSE'], ['id' => 'new', 'accessDepartment' => 'Finance']] as $row) {
        $this->postJson('/workspace/iso', ['revision' => 1, 'payload' => ['records' => ['targets' => [$row]]]])->assertForbidden();
    }
});
test('requires independent HIRADC and audit permissions for read and write', function (): void {
    $u = User::factory()->departmentUser()->create(['allowed_modules' => ['targets']]);
    app(WorkspaceService::class)->snapshot($u);
    $this->actingAs($u)->withSession(['auth_version' => 1]);
    foreach (['risk', 'audit'] as $source) {
        $this->get('/dashboard?module='.$source)->assertForbidden();
        $this->postJson('/workspace/'.$source, ['revision' => 1, 'payload' => ['records' => []]])->assertForbidden();
    }
});
test('inherits audit department through related records without exposing other audits', function (): void {
    $u = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['audit']]);
    $service = app(WorkspaceService::class);
    $w = $service->workspace('audit');
    $w->payload = ['audits' => [['id' => 'A', 'auditee' => 'HSE'], ['id' => 'B', 'auditee' => 'Finance']], 'findings' => [['id' => 'F', 'auditId' => 'A'], ['id' => 'G', 'auditId' => 'B']]];
    $w->save();
    $d = $service->scoped($u, 'audit', $w->payload);
    expect(array_column($d['findings'], 'id'))->toBe(['F']);
});
test('all three complete module pages load and invalid module selection is rejected', function (): void {
    $u = workspaceAdmin();
    $this->actingAs($u)->withSession(['auth_version' => 1]);
    foreach (['iso', 'risk', 'audit'] as $source) {
        $this->get('/dashboard?module='.$source)->assertOk()->assertSee('SILESTARI_SERVER');
    }$this->get('/dashboard?module=invalid')->assertNotFound();
});
test('reassembles a large attachment upload once and preserves it exactly', function (): void {
    $u = workspaceAdmin();
    $service = app(WorkspaceService::class);
    $w = $service->workspace('iso');
    $data = $w->payload;
    $data['records']['documents'] = [['id' => 'large', 'title' => 'Large attachment', 'attachment' => str_repeat('abcdef', 420000)]];
    $json = json_encode(['revision' => 1, 'payload' => $data]);
    $parts = str_split($json, 130000);
    $uuid = (string) Str::uuid();
    $this->actingAs($u)->withSession(['auth_version' => 1]);
    foreach ($parts as $index => $part) {
        $response = $this->postJson('/workspace/iso/chunks', ['upload' => $uuid, 'index' => $index, 'total' => count($parts), 'data' => $part])->assertOk();
    }
    $response->assertJsonPath('revision', 2);
    expect($w->fresh()->payload['records']['documents'][0]['attachment'])->toBe($data['records']['documents'][0]['attachment']);
    expect($w->fresh()->history)->toHaveCount(1);
});
test('password changes keep the current session but revoke older sessions', function (): void {
    $u = User::factory()->admin()->create(['password' => 'OldPassword123']);
    $this->actingAs($u)->withSession(['auth_version' => 1])->put('/password', ['current_password' => 'Wrong', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertSessionHasErrors('current_password');
    $this->put('/password', ['current_password' => 'OldPassword123', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertRedirect('/dashboard')->assertSessionHas('auth_version', 2);
    expect(Hash::check('NewPassword123', $u->fresh()->password))->toBeTrue();
});
test('only administrators can download the combined backup', function (): void {
    $worker = User::factory()->departmentUser()->create();
    $this->actingAs($worker)->withSession(['auth_version' => 1])->get('/workspace-backup')->assertForbidden();
    $admin = workspaceAdmin();
    $this->actingAs($admin)->withSession(['auth_version' => 1])->get('/workspace-backup')->assertOk()->assertDownload('SILESTARI-Laravel-cadangan-'.now()->format('Y-m-d').'.json');
});
test('persists department risk documents and entry choices while keeping foreign settings protected', function (): void {
    $u = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['risk']]);
    $service = app(WorkspaceService::class);
    $w = $service->workspace('risk');
    $w->payload = ['schema' => 1, 'revision' => 1, 'records' => [], 'archives' => [], 'documents' => ['2026::Finance' => ['dept' => 'Finance', 'name' => 'Secret']], 'departments' => [['name' => 'HSE', 'units' => ['Existing']], ['name' => 'Finance', 'units' => ['Finance unit']]], 'settings' => ['threshold' => 15, 'activities' => [['dept' => 'Finance', 'unit' => 'F', 'name' => 'Finance activity']]]];
    $w->save();
    $d = $service->scoped($u, 'risk', $w->payload);
    expect($d['documents'])->toBe([]);
    $d['documents']['2026::HSE'] = ['dept' => 'HSE', 'preparedName' => 'Worker'];
    $d['settings']['activities'][] = ['dept' => 'HSE', 'unit' => 'New', 'name' => 'Inspect'];
    $d['settings']['threshold'] = 1;
    $d['departments'][0]['units'][] = 'New';
    $this->actingAs($u)->withSession(['auth_version' => 1])->postJson('/workspace/risk', ['revision' => 1, 'payload' => $d])->assertOk();
    $actual = $w->fresh()->payload;
    expect($actual['documents']['2026::Finance']['name'])->toBe('Secret');
    expect($actual['documents']['2026::HSE']['preparedName'])->toBe('Worker');
    expect($actual['settings']['threshold'])->toBe(15);
    expect($actual['settings']['activities'])->toHaveCount(2);
    expect($actual['departments'][0]['units'])->toBe(['Existing', 'New']);
});

test('shows migrated accounts and management actions directly inside the workspace account page', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->departmentUser()->create(['name' => 'Tri Amdani K.', 'username' => 'hse_03', 'department' => 'HRGA', 'allowed_modules' => ['risk', 'audit']]);
    $response = $this->actingAs($admin)->withSession(['auth_version' => 1])->get('/dashboard?view=accounts')->assertOk();
    preg_match('/window\.SILESTARI_SERVER=(.*?);<\/script>/s', $response->getContent(), $matches);
    $boot = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    expect($boot['accountsHTML'])->toContain('Tri Amdani K.', 'hse_03', 'HRGA', 'Buat akun baru', 'Kelola akun', 'HIRADC &amp; IADL', 'Audit Internal');
    expect($boot['accountsHTML'])->not->toContain('password', 'legacy_password_hash');
});

test('does not send the account list to ordinary workspace users', function (): void {
    User::factory()->admin()->create(['name' => 'Private Account Directory Name']);
    $worker = User::factory()->departmentUser()->create(['allowed_modules' => ['targets']]);
    $response = $this->actingAs($worker)->withSession(['auth_version' => 1])->get('/dashboard?view=accounts')->assertOk()->assertDontSee('Private Account Directory Name');
    preg_match('/window\.SILESTARI_SERVER=(.*?);<\/script>/s', $response->getContent(), $matches);
    $boot = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    expect($boot['accountsHTML'])->toBeNull();
});
