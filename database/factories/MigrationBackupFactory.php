<?php

namespace Database\Factories;

use App\Models\MigrationBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MigrationBackup> */
class MigrationBackupFactory extends Factory
{
    public function definition(): array
    {
        return ['source' => 'iso', 'filename' => 'example.json', 'checksum' => hash('sha256', fake()->uuid()), 'record_count' => 0, 'record_counts' => [], 'payload' => ['version' => 1, 'company' => [], 'records' => []]];
    }
}
