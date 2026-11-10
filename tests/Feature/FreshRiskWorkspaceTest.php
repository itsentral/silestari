<?php

use App\Models\User;
use App\Models\Workspace;

test('first HIRADC snapshot initializes the current year and required settings without inserting example records', function (): void {
    $this->travelTo(now()->setDate(2027, 1, 1)->setTime(8, 0));
    $user = User::factory()->admin()->create();

    $response = $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])->getJson('/workspace');

    $response->assertOk()->assertJsonPath('risk.payload.years', [2027])->assertJsonPath('risk.payload.settings.thresholds', [2, 4, 8, 12])->assertJsonPath('risk.payload.settings.company', '')->assertJsonCount(0, 'risk.payload.records')->assertJsonPath('risk.revision', 1);
    expect(Workspace::where('source', 'risk')->sole()->payload['years'])->toBe([2027]);
});
