<?php

use App\Models\CommunicationMatrix;
use App\Models\ControlledDocument;
use App\Models\CsmsAccount;
use App\Models\CsmsVendor;
use App\Models\DocumentAttachment;
use App\Models\User;

function dashboardNativeCounts($response): array
{
    $response->assertOk();
    preg_match('/window\.SILESTARI_SERVER=(.*?);<\/script>/s', $response->getContent(), $matches);

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['nativeCounts'];
}

test('module cards count native data and document groups within account scope', function (bool $admin, array $expected): void {
    $user = $admin ? User::factory()->admin()->create() : User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['communication', 'documents', 'csms']]);
    foreach (['HSE', 'HRGA'] as $i => $department) {
        CommunicationMatrix::create(['year' => 2026, 'department' => $department, 'creator_id' => $user->id, 'payload' => [], 'signatures' => [], 'revision' => 1]);
        ControlledDocument::create(['number' => 'PROC-'.$i, 'department' => $department, 'creator_id' => $user->id]);
        $group = '2c9873f8-9125-4919-8783-'.str_pad((string) $i, 12, '0', STR_PAD_LEFT);
        foreach ($i === 0 ? [1, 2] : [1] as $version) {
            DocumentAttachment::create(['group_uuid' => $group, 'kind' => 'pedoman', 'number' => 'PED-'.$i, 'title' => 'Test document', 'department' => $department, 'version' => $version, 'original_name' => 'test.pdf', 'path' => 'test.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64), 'change_summary' => 'Test', 'uploaded_by' => $user->id, 'uploaded_by_name' => $user->name]);
        }
    }
    // Department users who created a foreign procedure may still see it under the existing workflow rules.
    if (! $admin) {
        $other = User::factory()->admin()->create();
        ControlledDocument::where('department', 'HRGA')->update(['creator_id' => $other->id]);
    }
    ControlledDocument::create(['number' => 'ARCHIVED', 'department' => 'HSE', 'creator_id' => $user->id, 'is_archived' => true]);
    $vendorAccount = CsmsAccount::create(['name' => 'Vendor', 'email' => 'counts@example.test', 'password' => 'Testing12345!', 'roles' => ['vendor']]);
    CsmsVendor::create(['id' => 'COUNTS-TEST', 'owner_id' => $vendorAccount->id, 'name' => 'PT Test', 'scope' => 'Test', 'status' => 'Menunggu Pengisian']);

    $response = $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])->get('/dashboard?view=execution');

    expect(dashboardNativeCounts($response))->toBe($expected);
})->with([
    [true, ['communication' => 2, 'documents' => 4, 'csms' => 1]],
    [false, ['communication' => 1, 'documents' => 2, 'csms' => 1]],
]);

test('module counts are not exposed to an account without native module access', function (): void {
    $user = User::factory()->departmentUser()->create(['department' => 'HSE', 'allowed_modules' => ['company']]);
    CommunicationMatrix::create(['year' => 2026, 'department' => 'HSE', 'creator_id' => $user->id, 'payload' => [], 'signatures' => [], 'revision' => 1]);

    $response = $this->actingAs($user)->withSession(['auth_version' => $user->auth_version])->get('/dashboard');

    expect(dashboardNativeCounts($response))->toBe([]);
});
