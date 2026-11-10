<?php

use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\DB;

function sdsFile(): array
{
    $data = "%PDF-1.4\nDokumen SDS\n%%EOF";

    return ['name' => 'SDS bahan.pdf', 'mime' => 'application/pdf', 'size' => strlen($data), 'data' => 'data:application/pdf;base64,'.base64_encode($data)];
}
test('B3 stores opens and removes SDS with encrypted workspace and backup', function (): void {
    $u = User::factory()->admin()->create();
    $w = app(WorkspaceService::class)->workspace('iso');
    $data = $w->payload;
    $data['records']['chemicals'] = [['id' => 'B3-1', 'title' => 'Bahan A', 'dept' => 'HSE', 'sdsAttachment' => sdsFile()]];
    $this->actingAs($u)->withSession(['auth_version' => 1])->postJson('/workspace/iso', ['revision' => 1, 'payload' => $data])->assertOk();
    expect($w->fresh()->payload['records']['chemicals'][0]['sdsAttachment'])->toBe(sdsFile());
    expect(DB::table('workspaces')->where('source', 'iso')->value('payload'))->not->toContain('SDS bahan.pdf');
    $this->get('/dashboard')->assertOk()->assertSee('/js/chemicals-sds.js', false)->assertSee('chemicalSdsOpen', false);
    expect($this->get('/workspace-backup')->assertOk()->streamedContent())->toContain('SDS bahan.pdf');
    $data['records']['chemicals'][0]['sdsAttachment'] = null;
    $this->postJson('/workspace/iso', ['revision' => 2, 'payload' => $data])->assertOk();
    expect($w->fresh()->payload['records']['chemicals'][0]['sdsAttachment'])->toBeNull();
});
test('B3 rejects disguised invalid and oversized SDS without changing existing records', function (): void {
    $u = User::factory()->admin()->create();
    $w = app(WorkspaceService::class)->workspace('iso');
    $data = $w->payload;
    $this->actingAs($u)->withSession(['auth_version' => 1]);
    foreach ([[...sdsFile(), 'data' => 'data:application/pdf;base64,'.base64_encode('<script>bad</script>')], [...sdsFile(), 'size' => 2097153], [...sdsFile(), 'mime' => 'text/html']] as $bad) {
        $data['records']['chemicals'] = [['id' => 'B3-1', 'title' => 'Bahan A', 'sdsAttachment' => $bad]];
        $this->postJson('/workspace/iso', ['revision' => 1, 'payload' => $data])->assertUnprocessable();
    }
    expect($w->fresh()->revision)->toBe(1);
});
test('SDS follows module and department access rules', function (): void {
    $u = User::factory()->departmentUser()->create(['department' => 'HRGA', 'allowed_modules' => ['chemicals']]);
    $w = app(WorkspaceService::class)->workspace('iso');
    $data = $w->payload;
    $data['records']['chemicals'] = [['id' => 'HSE-1', 'title' => 'Rahasia', 'accessDepartment' => 'HSE', 'sdsAttachment' => sdsFile()]];
    $w->payload = $data;
    $w->save();
    $this->actingAs($u)->withSession(['auth_version' => 1])->getJson('/workspace')->assertOk()->assertDontSee('SDS bahan.pdf');
    $own = app(WorkspaceService::class)->scoped($u, 'iso', $data);
    $own['records']['chemicals'] = [['id' => 'HRGA-1', 'title' => 'Bahan sendiri', 'dept' => 'HRGA', 'sdsAttachment' => sdsFile()]];
    $this->postJson('/workspace/iso', ['revision' => 1, 'payload' => $own])->assertOk();
    expect($w->fresh()->payload['records']['chemicals'])->toHaveCount(2);
});
