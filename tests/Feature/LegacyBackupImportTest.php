<?php

use App\Models\MigrationBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('imports an intact encrypted backup without creating accounts and skips duplicates', function (): void {
    $dir = storage_path('framework/testing/legacy-'.Str::uuid());
    File::ensureDirectoryExists($dir);
    $data = ['version' => 1, 'company' => ['name' => 'Sensitive test company'], 'records' => ['targets' => [['id' => 'T1', 'title' => 'Example']]], 'printForms' => ['keep' => 'signature data']];
    file_put_contents($dir.'/iso.json', json_encode($data));
    try {
        $this->artisan('silestari:import-backups', ['directory' => $dir])->assertSuccessful();
        $backup = MigrationBackup::query()->sole();
        expect($backup->payload)->toBe($data);
        expect($backup->record_count)->toBe(1);
        expect(DB::table('migration_backups')->value('payload'))->not->toContain('Sensitive test company');
        expect($backup->toArray())->not->toHaveKey('payload');
        $this->assertDatabaseCount('users', 0);
        $this->artisan('silestari:import-backups', ['directory' => $dir])->assertSuccessful();
        $this->assertDatabaseCount('migration_backups', 1);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('recognizes an empty HIRADC backup without inventing records', function (): void {
    $dir = storage_path('framework/testing/legacy-'.Str::uuid());
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/risk.json', json_encode(['schema' => 1, 'records' => [], 'settings' => [], 'departments' => []]));
    try {
        $this->artisan('silestari:import-backups', ['directory' => $dir])->assertSuccessful();
        expect(MigrationBackup::query()->sole()->record_count)->toBe(0);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('validates files before committing any backup', function (): void {
    $dir = storage_path('framework/testing/legacy-'.Str::uuid());
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/a-valid.json', json_encode(['version' => 1, 'company' => [], 'records' => []]));
    file_put_contents($dir.'/z-invalid.json', '{broken');
    try {
        $this->artisan('silestari:import-backups', ['directory' => $dir])->assertFailed();
        $this->assertDatabaseCount('migration_backups', 0);
    } finally {
        File::deleteDirectory($dir);
    }
});
test('checks a backup in dry run without writing data', function (): void {
    $dir = storage_path('framework/testing/legacy-'.Str::uuid());
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/a.json', json_encode(['version' => 1, 'company' => [], 'records' => []]));
    try {
        $this->artisan('silestari:import-backups', ['directory' => $dir, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('migration_backups', 0);
    } finally {
        File::deleteDirectory($dir);
    }
});
