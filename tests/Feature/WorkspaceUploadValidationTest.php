<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceService;
use Illuminate\Support\Str;

test('malformed JSON upload returns 422 and removes staging without changing workspaces', function (string $source): void {
    $actor = User::factory()->admin()->create();
    app(WorkspaceService::class)->snapshot($actor);
    $before = Workspace::orderBy('id')->get()->map->getAttributes()->all();
    $upload = (string) Str::uuid();
    $this->actingAs($actor)->withSession(['auth_version' => $actor->auth_version]);

    $this->postJson('/workspace/'.$source.'/chunks', ['upload' => $upload, 'index' => 0, 'total' => 1, 'data' => '{broken JSON'])->assertUnprocessable();

    expect(Workspace::orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
    expect(is_dir(storage_path('app/private/workspace-uploads/'.$actor->id.'/'.$upload)))->toBeFalse();
})->with(['iso', 'risk', 'audit', 'policy', 'document-files']);
