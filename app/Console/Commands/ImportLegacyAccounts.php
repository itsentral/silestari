<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ImportLegacyAccounts extends Command
{
    protected $signature = 'silestari:import-accounts {file : Cadangan akun dari aplikasi lama} {--dry-run : Periksa tanpa menyimpan}';

    protected $description = 'Memindahkan akun lama, izin, departemen dan verifikasi password tanpa membuat password baru untuk pengguna.';

    public function handle(): int
    {
        try {
            $file = (string) $this->argument('file');
            if (! is_file($file) || filesize($file) > 1024 * 1024) {
                throw new \RuntimeException('File akun tidak ditemukan atau melebihi 1 MB.');
            }
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($data, ['schemaVersion' => ['required', Rule::in([1])], 'type' => ['required', Rule::in(['silestari-accounts'])], 'accounts' => ['required', 'array', 'min:1'], 'accounts.*.id' => ['required', 'string', 'max:255', 'distinct'], 'accounts.*.name' => ['required', 'string', 'max:150'], 'accounts.*.username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', 'distinct:ignore_case'], 'accounts.*.role' => ['required', Rule::in(['admin', 'user'])], 'accounts.*.active' => ['required', 'boolean'], 'accounts.*.allowed' => ['present', 'array'], 'accounts.*.allowed.*' => ['string'], 'accounts.*.department' => ['nullable', 'string', 'max:150'], 'accounts.*.hiradcDepartment' => ['nullable', 'string', 'max:150'], 'accounts.*.salt' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/i'], 'accounts.*.hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i']])->validate();
            if (! User::query()->where('role', 'admin')->where('is_active', true)->exists() && ! collect($data['accounts'])->contains(fn (array $a): bool => $a['role'] === 'admin' && (bool) $a['active'])) {
                throw new \RuntimeException('Cadangan harus memuat minimal satu admin aktif.');
            }
            foreach ($data['accounts'] as $account) {
                $username = mb_strtolower($account['username']);
                $existing = User::query()->where('username', $username)->first();
                if ($existing && $existing->legacy_account_id !== $account['id']) {
                    throw new \RuntimeException('Username bentrok dengan akun lain. Tidak ada data yang diubah.');
                }
                if ($account['role'] === 'user' && blank($account['department'] ?? $account['hiradcDepartment'] ?? null)) {
                    throw new \RuntimeException('Departemen akun pengguna belum ditentukan pada cadangan.');
                }
            }
            if ($this->option('dry-run')) {
                $this->info(count($data['accounts']).' akun valid. Tidak ada perubahan.');

                return self::SUCCESS;
            }
            $count = DB::transaction(function () use ($data): int {
                $installation = DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
                if (! $installation) {
                    throw new \RuntimeException('Migrasi pengaturan awal belum dijalankan.');
                }
                $n = 0;
                foreach ($data['accounts'] as $account) {
                    if (User::query()->where('legacy_account_id', $account['id'])->exists()) {
                        continue;
                    }
                    $user = new User;
                    $user->fill(['name' => $account['name'], 'email' => null, 'password' => Str::random(64)]);
                    $user->forceFill(['username' => mb_strtolower($account['username']), 'legacy_account_id' => $account['id'], 'legacy_password_hash' => mb_strtolower($account['hash']), 'legacy_password_salt' => $account['salt'], 'role' => $account['role'], 'is_active' => (bool) $account['active'], 'department' => $account['department'] ?? $account['hiradcDepartment'] ?? null, 'allowed_modules' => array_values(array_intersect($account['allowed'], array_keys(ModuleRegistry::all()))), 'auth_version' => 1])->save();
                    $n++;
                }
                DB::table('app_installations')->where('id', 1)->update(['completed_at' => now()]);

                return $n;
            });
            $this->info($count.' akun lama dipindahkan. Gunakan username dan password lama.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Impor akun dibatalkan: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
