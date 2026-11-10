<?php

namespace App\Support;

use App\Models\User;

class WorkspaceNavigation
{
    private const GROUPS = ['context' => ['parties', 'issues'], 'planning' => ['risk', 'actions', 'legal', 'targets', 'moc'], 'execution' => ['chemicals', 'waste', 'solidWaste', 'emergency', 'communication', 'documents', 'training', 'csms', 'workPermits'], 'monitoring' => ['monitor', 'analysis', 'audit', 'managementReview'], 'improvementHub' => ['nonconformity', 'incidents', 'kaizen']];

    public static function sections(User $user): array
    {
        $sections = [['label' => '', 'items' => [['dashboard', 'Dashboard', 'home'], ['company', 'Profil Perusahaan', 'company'], ['policy', 'Kebijakan Perusahaan', 'policy']]], ['label' => 'SISTEM MANAJEMEN K3L', 'items' => [['context', 'Konteks Organisasi', 'context'], ['planning', 'Perencanaan', 'planning'], ['execution', 'Pelaksanaan', 'execution'], ['monitoring', 'Pemantauan', 'monitoring'], ['improvementHub', 'Improvement', 'improvement'], ['risk', 'HIRADC & IADL', 'risk'], ['audit', 'Audit Internal', 'audit']]], ['label' => 'PUSTAKA & PENGATURAN', 'items' => [['templates', 'Template Excel', 'templates'], ['integrations', 'Integrasi Aplikasi', 'integrations'], ['data', 'Data & Cadangan', 'data'], ['accounts', 'Akun & Hak Akses', 'accounts']]]];
        $icons = json_decode(file_get_contents(resource_path('views/layouts/workspace-icons.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($sections as &$section) {
            $out = [];
            foreach ($section['items'] as [$key,$name,$icon]) {
                $allowed = $key === 'dashboard' || $user->isAdmin() || $user->hasModule($key);
                if (isset(self::GROUPS[$key])) {
                    $allowed = $user->isAdmin() || collect(self::GROUPS[$key])->contains(fn (string $child): bool => $user->hasModule($child));
                }if (! $allowed) {
                    continue;
                }$url = $key === 'policy' ? route('policy.index') : route('dashboard', in_array($key, ['risk', 'audit'], true) ? ['module' => $key] : ['view' => $key]);
                $out[] = ['key' => $key, 'name' => $name, 'path' => $icons[$icon] ?? $icons['default'], 'url' => $url];
            }$section['items'] = $out;
        }
        unset($section);

        return array_values(array_filter($sections, fn (array $section): bool => $section['items'] !== []));
    }
}
