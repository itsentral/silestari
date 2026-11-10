<?php

namespace App\Services;

use App\Models\MigrationBackup;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkspaceService
{
    public const SOURCES = ['iso', 'risk', 'audit'];

    public const ALIASES = ['trainingMatrix' => 'training', 'trainingNeeds' => 'training', 'trainingProgram' => 'training', 'trainingEvaluation' => 'training', 'wastePermits' => 'waste', 'wasteTypes' => 'waste', 'wasteLog' => 'waste', 'wasteBalance' => 'waste', 'contextIssueDocs' => 'issues', 'contextPartyDocs' => 'parties', 'emergencySchedules' => 'emergency', 'emergencyStructure' => 'emergency', 'emergencyDuties' => 'emergency', 'emergencyReports' => 'emergency', 'emergencyEquipment' => 'emergency', 'emergencyEquipmentSchedule' => 'emergency', 'emergencyEquipmentChecklist' => 'emergency', 'regulations' => 'legal', 'regulationUpdates' => 'legal', 'regulationNotes' => 'legal', 'reviewSchedules' => 'managementReview', 'reviewMaterials' => 'managementReview', 'reviewOutputs' => 'managementReview'];

    public function can(User $user, string $key): bool
    {
        return $user->hasModule(self::ALIASES[$key] ?? $key);
    }

    public function workspace(string $source): Workspace
    {
        abort_unless(in_array($source, self::SOURCES, true), 404);

        return Workspace::firstOrCreate(['source' => $source], ['payload' => MigrationBackup::where('source', $source)->latest('id')->first()?->payload ?? $this->empty($source), 'revision' => 1, 'history' => []]);
    }

    private function empty(string $source): array
    {
        return match ($source) {
            'iso' => ['version' => 1, 'company' => [], 'records' => [], 'links' => [], 'edits' => []], 'risk' => ['schema' => 1, 'revision' => 1, 'years' => [now()->timezone('Asia/Jakarta')->year], 'departments' => [], 'records' => [], 'archives' => [], 'documents' => [], 'audit' => [], 'settings' => ['thresholds' => [2, 4, 8, 12], 'riskScale' => 'total-v1', 'company' => '']],default => ['schemaVersion' => 1, 'programs' => [], 'audits' => [], 'checks' => [], 'findings' => [], 'actions' => [], 'risks' => [], 'reports' => [], 'master' => []]
        };
    }

    private function owner(array $row, string $source, string $type, array $payload, array $seen = []): string
    {
        $owner = $source === 'risk' ? ($row['dept'] ?? '') : ($row['accessDepartment'] ?? $row['dept'] ?? '');
        if ($owner !== '') {
            return (string) $owner;
        }
        if ($source !== 'audit') {
            return '';
        }
        if ($type === 'audits' && ! empty($row['auditee'])) {
            return (string) $row['auditee'];
        }
        foreach (['auditId' => 'audits', 'findingId' => 'findings', 'programId' => 'programs'] as $field => $collection) {
            if (empty($row[$field]) || in_array($collection.':'.$row[$field], $seen, true)) {
                continue;
            }$seen[] = $collection.':'.$row[$field];
            foreach ($payload[$collection] ?? [] as $parent) {
                if (($parent['id'] ?? null) === $row[$field]) {
                    return $this->owner($parent, $source, $collection, $payload, $seen);
                }
            }
        }

        return '';
    }

    private function own(array $row, User $user, string $source, string $type, array $payload): bool
    {
        return filled($user->department) && $this->owner($row, $source, $type, $payload) === $user->department;
    }

    private function collections(string $source, array $payload): array
    {
        return $source === 'iso' ? array_keys($payload['records'] ?? []) : ($source === 'risk' ? ['records'] : ['programs', 'audits', 'checks', 'findings', 'actions', 'risks', 'reports']);
    }

    public function scoped(User $user, string $source, array $payload): array
    {
        if ($user->isAdmin()) {
            return $payload;
        }
        if ($source !== 'iso' && ! $user->hasModule($source)) {
            return $this->empty($source);
        }
        $out = $payload;
        foreach ($this->collections($source, $payload) as $key) {
            $rows = $source === 'iso' ? ($payload['records'][$key] ?? []) : ($payload[$key] ?? []);
            $allowed = $source !== 'iso' || $this->can($user, $key);
            $filtered = $allowed ? array_values(array_filter($rows, fn ($r) => is_array($r) && $this->own($r, $user, $source, $key, $payload))) : [];
            if ($source === 'iso') {
                $out['records'][$key] = $filtered;
            } else {
                $out[$key] = $filtered;
            }
        }
        if ($source === 'iso') {
            foreach (['monitorReviews' => 'monitor', 'complianceReviews' => 'legal', 'analysisAspects' => 'analysis'] as $key => $module) {
                $out[$key] = $user->hasModule($module) ? array_values(array_filter($payload[$key] ?? [], fn ($r) => is_array($r) && $this->own($r, $user, 'iso', $key, $payload))) : [];
            }
            $out['links'] = [];
            $out['edits'] = [];
            $out['printForms'] = [];
            foreach ($payload['printForms'] ?? [] as $key => $form) {
                $module = str_starts_with((string) $key, 'audit-') ? 'audit' : $key;
                if (! $this->can($user, $module)) {
                    continue;
                }$form['approvals'] = array_filter($form['approvals'] ?? [], fn ($scope) => str_ends_with((string) $scope, '::'.$user->department), ARRAY_FILTER_USE_KEY);
                $out['printForms'][$key] = $form;
            }
        } else {
            $out['audit'] = [];
            $out['source'] = [];
        }

        if ($source === 'risk') {
            $out['archives'] = array_values(array_filter($payload['archives'] ?? [], fn ($r) => is_array($r) && $this->own($r, $user, $source, 'archives', $payload)));
            $out['documents'] = array_filter($payload['documents'] ?? [], fn ($r, $key) => is_array($r) && (($r['dept'] ?? '') === $user->department || str_ends_with((string) $key, '::'.$user->department)), ARRAY_FILTER_USE_BOTH);
            $out['settings']['activities'] = array_values(array_filter($payload['settings']['activities'] ?? [], fn ($r) => ($r['dept'] ?? '') === $user->department));
            if (($out['settings']['entryContext']['dept'] ?? '') !== $user->department) {
                $out['settings']['entryContext'] = [];
            }
        }

        return $out;
    }

    public function snapshot(User $user): array
    {
        $out = [];
        foreach (self::SOURCES as $source) {
            $w = $this->workspace($source);
            $out[$source] = ['revision' => $w->revision, 'payload' => $this->scoped($user, $source, $w->payload)];
        }

        return $out;
    }

    private function mergeRows(User $user, string $source, string $key, array $before, array $incoming, array $full): array
    {
        $foreign = array_values(array_filter($before, fn ($r) => ! $this->own($r, $user, $source, $key, $full)));
        $foreignIds = array_column($foreign, 'id');
        $rows = [];
        $seen = [];
        foreach ($incoming as $row) {
            abort_unless(is_array($row), 422);
            $id = $row['id'] ?? null;
            abort_unless(is_string($id) || is_int($id), 422);
            abort_if(in_array($id, $foreignIds, true) || in_array($id, $seen, true), 403);
            $seen[] = $id;
            $owner = $this->owner($row, $source, $key, $full);
            abort_if($owner !== '' && $owner !== $user->department, 403);
            $row[$source === 'risk' ? 'dept' : 'accessDepartment'] = $user->department;
            $rows[] = $row;
        }

        return [...$foreign, ...$rows];
    }

    public function save(User $user, string $source, int $revision, array $incoming): array
    {
        abort_unless(in_array($source, self::SOURCES, true), 404);
        abort_if($source !== 'iso' && ! $user->hasModule($source), 403);

        $rules = $source === 'iso' ? ['records' => 'sometimes|array', 'records.*' => 'array', 'records.*.*' => 'array', 'company' => 'sometimes|array', 'printForms' => 'sometimes|array'] : ($source === 'risk' ? ['records' => 'sometimes|array', 'records.*' => 'array', 'documents' => 'sometimes|array', 'settings' => 'sometimes|array', 'departments' => 'sometimes|array'] : ['programs' => 'sometimes|array', 'audits' => 'sometimes|array', 'checks' => 'sometimes|array', 'findings' => 'sometimes|array', 'actions' => 'sometimes|array', 'risks' => 'sometimes|array', 'reports' => 'sometimes|array']);
        Validator::make($incoming, $rules)->validate();

        return DB::transaction(function () use ($user, $source, $revision, $incoming): array {
            $w = Workspace::where('source', $source)->lockForUpdate()->firstOrFail();
            abort_if($w->revision !== $revision, 409, 'Data telah diubah pengguna lain. Muat ulang halaman sebelum menyimpan.');
            $before = $w->payload;
            $next = $incoming;
            if (! $user->isAdmin()) {
                $next = $before;
                $keys = array_unique([...$this->collections($source, $before), ...$this->collections($source, $incoming)]);
                foreach ($keys as $key) {
                    if ($source === 'iso' && ! $this->can($user, $key)) {
                        continue;
                    }$old = $source === 'iso' ? ($before['records'][$key] ?? []) : ($before[$key] ?? []);
                    $rows = $source === 'iso' ? ($incoming['records'][$key] ?? []) : ($incoming[$key] ?? []);
                    abort_unless(is_array($rows), 422);
                    $merged = $this->mergeRows($user, $source, $key, $old, $rows, $before);
                    if ($source === 'iso') {
                        $next['records'][$key] = $merged;
                    } else {
                        $next[$key] = $merged;
                    }
                }
                if ($source === 'iso') {
                    foreach (['monitorReviews' => 'monitor', 'complianceReviews' => 'legal', 'analysisAspects' => 'analysis'] as $key => $module) {
                        if ($user->hasModule($module)) {
                            $next[$key] = $this->mergeRows($user, $source, $key, $before[$key] ?? [], $incoming[$key] ?? [], $before);
                        }
                    }
                    foreach (['company' => ['name', 'industry', 'address', 'contact', 'representative', 'scope'], 'policy' => ['policyTitle', 'approved', 'policyDate', 'review', 'policy']] as $module => $fields) {
                        if ($user->hasModule($module)) {
                            foreach ($fields as $field) {
                                if (array_key_exists($field, $incoming['company'] ?? [])) {
                                    $next['company'][$field] = $incoming['company'][$field];
                                }
                            }
                        }
                    }
                    foreach ($incoming['printForms'] ?? [] as $key => $value) {
                        $module = str_starts_with((string) $key, 'audit-') ? 'audit' : $key;
                        if (! $this->can($user, $module)) {
                            continue;
                        }foreach ($value['approvals'] ?? [] as $scope => $approval) {
                            if (str_ends_with((string) $scope, '::'.$user->department) && is_array($approval)) {
                                $approval['recordedBy'] = $user->id;
                                $approval['recordedAt'] = now()->toIso8601String();
                                $next['printForms'][$key]['approvals'][$scope] = $approval;
                            }
                        }
                    }
                } else {
                    $next['revision'] = $incoming['revision'] ?? ($before['revision'] ?? 1);
                    if ($source === 'risk') {
                        foreach ($before['documents'] ?? [] as $key => $doc) {
                            if (($doc['dept'] ?? '') === $user->department || str_ends_with((string) $key, '::'.$user->department)) {
                                unset($next['documents'][$key]);
                            }
                        }
                        foreach ($incoming['documents'] ?? [] as $key => $doc) {
                            if (is_array($doc) && (($doc['dept'] ?? '') === $user->department || str_ends_with((string) $key, '::'.$user->department))) {
                                $old = $before['documents'][$key] ?? null;
                                abort_if($old && ($old['dept'] ?? '') !== $user->department && ! str_ends_with((string) $key, '::'.$user->department), 403);
                                $doc['dept'] = $user->department;
                                $next['documents'][$key] = $doc;
                            }
                        }
                        $foreign = array_values(array_filter($before['settings']['activities'] ?? [], fn ($r) => ($r['dept'] ?? '') !== $user->department));
                        $own = array_values(array_filter($incoming['settings']['activities'] ?? [], fn ($r) => is_array($r) && ($r['dept'] ?? '') === $user->department));
                        $next['settings']['activities'] = [...$foreign, ...$own];
                        if (($incoming['settings']['entryContext']['dept'] ?? '') === $user->department) {
                            $next['settings']['entryContext'] = $incoming['settings']['entryContext'];
                        }
                        foreach ($next['departments'] ?? [] as $index => $department) {
                            if (($department['name'] ?? '') !== $user->department) {
                                continue;
                            }foreach ($incoming['departments'] ?? [] as $candidate) {
                                if (($candidate['name'] ?? '') === $user->department) {
                                    $units = array_filter($candidate['units'] ?? [], fn ($unit) => is_string($unit) && strlen($unit) <= 300);
                                    $next['departments'][$index]['units'] = array_values(array_unique([...($department['units'] ?? []), ...$units]));
                                }
                            }
                        }
                    }
                }
            }
            if ($source === 'iso') {
                $protected = [];
                foreach ($before['records']['documents'] ?? [] as $row) {
                    if (! empty($row['nativeProcedureId'])) {
                        $protected[$row['id']] = $row;
                    }
                }
                $incoming = [];
                foreach ($next['records']['documents'] ?? [] as $row) {
                    $id = $row['id'] ?? '';
                    if (isset($protected[$id])) {
                        if (isset($incoming[$id]) || $protected[$id] != $row) {
                            throw ValidationException::withMessages(['documents' => 'Dokumen Published hanya dapat diperbarui melalui revisi Document Control.']);
                        }$incoming[$id] = $row;
                    } elseif (! empty($row['nativeProcedureId'])) {
                        throw ValidationException::withMessages(['documents' => 'Identitas dokumen Published tidak dapat dibuat melalui register lama.']);
                    }
                }
                foreach ($protected as $id => $row) {
                    if (! isset($incoming[$id])) {
                        throw ValidationException::withMessages(['documents' => 'Dokumen Published tidak dapat dihapus melalui register lama.']);
                    }
                }
            }
            if ($source === 'iso') {
                $this->validateSds($next['records']['chemicals'] ?? []);
            }
            if ($next !== $before) {
                $history = $w->history ?? [];
                $history[] = ['revision' => $w->revision, 'at' => now()->toIso8601String(), 'user_id' => $user->id, 'payload' => $before];
                $w->forceFill(['payload' => $next, 'revision' => $w->revision + 1, 'history' => array_slice($history, -2)])->save();
            }

            return ['revision' => $w->revision, 'payload' => $this->scoped($user, $source, $w->payload)];
        });
    }

    private function validateSds(array $rows): void
    {
        foreach ($rows as $row) {
            $file = $row['sdsAttachment'] ?? null;
            if ($file === null) {
                continue;
            }
            Validator::make(['sds' => $file], ['sds' => 'required|array:name,mime,size,data', 'sds.name' => 'required|string|max:240', 'sds.mime' => 'required|in:application/pdf,image/png,image/jpeg', 'sds.size' => 'required|integer|min:1|max:2097152', 'sds.data' => 'required|string|max:2800000'])->validate();
            if (! preg_match('/^data:(application\/pdf|image\/png|image\/jpeg);base64,([A-Za-z0-9+\/=]+)$/D', $file['data'], $match)) {
                throw ValidationException::withMessages(['sds' => 'Format SDS tidak sesuai.']);
            }
            $bytes = base64_decode($match[2], true);
            if ($bytes === false || strlen($bytes) !== (int) $file['size'] || strlen($bytes) > 2097152 || (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== $file['mime'] || $match[1] !== $file['mime']) {
                throw ValidationException::withMessages(['sds' => 'Isi SDS tidak sesuai format atau ukuran file. Gunakan PDF/JPG/PNG maksimal 2 MB.']);
            }
        }
    }
}
