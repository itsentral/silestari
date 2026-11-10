<?php

namespace App\Console\Commands;

use App\Models\MigrationBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportLegacyBackups extends Command
{
    protected $signature = 'silestari:import-backups {directory : Folder cadangan JSON} {--dry-run : Periksa tanpa menyimpan}';

    protected $description = 'Memeriksa dan menyimpan cadangan lama ISO, HIRADC dan audit secara utuh sebelum konversi modul.';

    public function handle(): int
    {
        $directory = (string) $this->argument('directory');
        if (! is_dir($directory)) {
            $this->error('Folder cadangan tidak ditemukan.');

            return self::FAILURE;
        }
        $prepared = [];
        try {
            foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
                if (filesize($path) > 10 * 1024 * 1024) {
                    throw new \RuntimeException('File melebihi 10 MB: '.basename($path));
                }
                $raw = file_get_contents($path);
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($data)) {
                    throw new \RuntimeException('Struktur JSON tidak valid.');
                }
                $counts = [];
                if (($data['version'] ?? null) === 1 && isset($data['company'],$data['records']) && is_array($data['records'])) {
                    $source = 'iso';
                    foreach ($data['records'] as $key => $rows) {
                        if (! is_array($rows)) {
                            throw new \RuntimeException('Daftar catatan ISO tidak valid.');
                        } $counts[$key] = count($rows);
                    }
                } elseif (($data['schema'] ?? null) === 1 && isset($data['records'],$data['settings'],$data['departments']) && is_array($data['records'])) {
                    $source = 'risk';
                    $counts = ['records' => count($data['records']), 'archives' => count($data['archives'] ?? [])];
                } elseif (($data['schemaVersion'] ?? null) === 1 && isset($data['programs'],$data['audits'],$data['findings'])) {
                    $source = 'audit';
                    foreach (['programs', 'audits', 'checks', 'findings', 'actions', 'risks', 'reports'] as $key) {
                        if (! isset($data[$key]) || ! is_array($data[$key])) {
                            throw new \RuntimeException('Daftar catatan audit tidak valid.');
                        } $counts[$key] = count($data[$key]);
                    }
                } else {
                    $this->line('Dilewati (bukan cadangan data operasional): '.basename($path));

                    continue;
                }
                $prepared[] = ['source' => $source, 'filename' => basename($path), 'checksum' => hash('sha256', $raw), 'record_count' => array_sum($counts), 'record_counts' => $counts, 'payload' => $data];
            }
            if (! $prepared) {
                throw new \RuntimeException('Tidak ada cadangan ISO/HIRADC/Audit yang dikenali.');
            }
            foreach ($prepared as $item) {
                $this->line($item['source'].': '.$item['record_count'].' catatan pada '.$item['filename']);
            }
            if ($this->option('dry-run')) {
                $this->info('Pemeriksaan selesai; database tidak diubah.');

                return self::SUCCESS;
            }
            $added = DB::transaction(function () use ($prepared): int {
                $n = 0;
                foreach ($prepared as $item) {
                    $backup = MigrationBackup::query()->firstOrCreate(['checksum' => $item['checksum']], $item);
                    if ($backup->wasRecentlyCreated) {
                        $n++;
                    }
                }

return $n;
            });
            $this->info($added.' cadangan baru tersimpan terenkripsi. Data belum dikonversi menjadi modul operasional.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Impor dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
