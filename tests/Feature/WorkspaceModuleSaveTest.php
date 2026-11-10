<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Support\Str;

it('persists add edit and deletion for each ISO register', function (string $module) {
    $actor = User::factory()->admin()->create();
    app(WorkspaceService::class)->snapshot($actor);
    $this->actingAs($actor)->withSession(['auth_version' => $actor->auth_version]);
    $payload = Workspace::where('source', 'iso')->first()->payload;
    $payload['records'][$module] = [['id' => 'SAVE-TEST', 'title' => 'Pengujian', 'dept' => 'HSE']];
    $this->postJson('/workspace/iso', ['revision' => 1, 'payload' => $payload])->assertOk()->assertJsonPath('revision', 2);
    expect(Workspace::where('source', 'iso')->first()->payload['records'][$module][0]['title'])->toBe('Pengujian');
    $payload['records'][$module][0]['title'] = 'Diperbarui';
    $this->postJson('/workspace/iso', ['revision' => 2, 'payload' => $payload])->assertOk()->assertJsonPath('revision', 3);
    expect(Workspace::where('source', 'iso')->first()->payload['records'][$module][0]['title'])->toBe('Diperbarui');
    $payload['records'][$module] = [];
    $this->postJson('/workspace/iso', ['revision' => 3, 'payload' => $payload])->assertOk()->assertJsonPath('revision', 4);
    expect(Workspace::where('source', 'iso')->first()->payload['records'][$module])->toBe([]);
})->with(['workPermits', 'regulations', 'regulationUpdates', 'regulationNotes', 'csms', 'training', 'nonconformity', 'incidents', 'kaizen', 'moc', 'reviewSchedules', 'reviewMaterials', 'reviewOutputs', 'managementReview', 'chemicals', 'solidWaste', 'emergency', 'communication', 'documents', 'scope', 'risk', 'opportunity', 'actions', 'legal', 'targets', 'operations', 'waste', 'monitor', 'analysis', 'audit', 'improvement']);

it('accepts larger bounded chunks and commits exactly once', function () {
    $actor = User::factory()->admin()->create();
    app(WorkspaceService::class)->snapshot($actor);
    $this->actingAs($actor)->withSession(['auth_version' => $actor->auth_version]);
    $payload = Workspace::where('source', 'iso')->first()->payload;
    $payload['company']['scope'] = str_repeat('Pengujian ', 70000);
    $json = json_encode(['revision' => 1, 'payload' => $payload], JSON_THROW_ON_ERROR);
    $this->postJson('/workspace/iso/chunks', ['upload' => (string) Str::uuid(), 'index' => 0, 'total' => 1, 'data' => $json])->assertOk()->assertJsonPath('revision', 2);
    expect(Workspace::where('source', 'iso')->first()->payload['company']['scope'])->toBe($payload['company']['scope']);
});
