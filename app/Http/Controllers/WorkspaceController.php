<?php

namespace App\Http\Controllers;

use App\Models\CsmsVendor;
use App\Models\DocumentAttachment;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\DocumentAttachmentService;
use App\Services\DocumentControlService;
use App\Services\PolicyDocumentService;
use App\Services\WorkspaceService;
use App\Support\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends Controller
{
    public function __construct(private WorkspaceService $workspaces) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $source = $request->query('module', 'iso');
        if ($request->query('view') === 'csms' && ! $request->boolean('legacy')) {
            abort_unless($request->user()->hasModule('csms'), 403);

            return redirect()->route('csms.portal');
        }
        if ($request->query('view') === 'communication' && ! $request->boolean('legacy')) {
            abort_unless($request->user()->hasModule('communication'), 403);

            return redirect()->route('communication.index');
        }
        if ($request->query('view') === 'documents' && ! $request->boolean('legacy')) {
            abort_unless($request->user()->hasModule('documents'), 403);

            return redirect()->route('document-control.index');
        }
        if ($request->query('view') === 'policy' || $source === 'policy') {
            abort_unless($request->user()->hasModule('policy'), 403);

            return redirect()->route('policy.index');
        }
        abort_unless(in_array($source, WorkspaceService::SOURCES, true), 404);
        abort_if($source !== 'iso' && ! $request->user()->hasModule($source), 403);
        $user = $request->user();
        $bootstrap = ['user' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'role' => $user->role, 'department' => $user->department, 'hiradcDepartment' => $user->department, 'allowed' => $user->allowed_modules ?? []], 'accountsHTML' => $user->isAdmin() ? view('users.workspace-list', ['accounts' => User::query()->select(['id', 'name', 'username', 'email', 'role', 'department', 'is_active', 'allowed_modules'])->orderBy('name')->orderBy('id')->get(), 'modules' => ModuleRegistry::all()])->render() : null, 'csrf' => csrf_token(), 'workspaces' => $this->workspaces->snapshot($user), 'nativeCounts' => $this->nativeCounts($user)];
        $html = file_get_contents(resource_path('legacy/'.$source.'.html'));
        $json = json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $injection = '<script src="/js/browser-compat.js"></script><script>window.SILESTARI_SERVER='.$json.';</script><script src="/js/workspace-bridge.js?v=20261109-performance"></script><script src="/js/workspace-shared.js?v=20261030"></script><script src="/js/chemicals-sds.js?v=20261026" defer></script>';

        return response(str_replace('</head>', '<link rel="stylesheet" href="/css/mobile.css?v=20261030-audit3"><script src="/js/mobile-navigation.js" defer></script></head>', str_replace('<head>', '<head>'.$injection, $html)))->header('Cache-Control', 'no-store, private');
    }

    private function nativeCounts(User $user): array
    {
        $counts = [];
        if ($user->hasModule('communication')) {
            $counts['communication'] = app(CommunicationService::class)->visible($user)->count();
        }
        if ($user->hasModule('documents')) {
            $files = DocumentAttachment::query();
            if (! $user->isAdmin()) {
                $files->where('department', $user->department);
            }
            $counts['documents'] = app(DocumentControlService::class)->visible($user)->where('is_archived', false)->count() + $files->distinct()->count('group_uuid');
        }
        if ($user->hasModule('csms')) {
            $counts['csms'] = CsmsVendor::count();
        }

        return $counts;
    }

    public function store(Request $request, string $source): JsonResponse
    {
        $validated = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'payload' => ['required', 'array']]);
        abort_if(strlen($request->getContent()) > 32 * 1024 * 1024, 413);

        return response()->json($this->workspaces->save($request->user(), $source, $validated['revision'], $validated['payload']));
    }

    public function snapshot(Request $request): JsonResponse
    {
        return response()->json($this->workspaces->snapshot($request->user()));
    }

    public function chunks(Request $request, string $source): JsonResponse
    {
        abort_unless(in_array($source, [...WorkspaceService::SOURCES, 'policy', 'document-files'], true), 404);
        abort_if($source !== 'iso' && ! $request->user()->hasModule($source === 'document-files' ? 'documents' : $source), 403);
        $v = $request->validate(['upload' => ['required', 'uuid'], 'index' => ['required', 'integer', 'min:0', 'max:255'], 'total' => ['required', 'integer', 'min:1', 'max:256'], 'data' => ['required', 'string', 'max:900000']]);
        abort_if($v['index'] >= $v['total'], 422);
        $root = storage_path('app/private/workspace-uploads/'.$request->user()->id);
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        foreach (glob($root.'/*') ?: [] as $old) {
            if (is_dir($old) && filemtime($old) < time() - 3600) {
                foreach (glob($old.'/*') ?: [] as $f) {
                    unlink($f);
                }rmdir($old);
            }
        }
        $size = 0;
        foreach (glob($root.'/*/*') ?: [] as $file) {
            $size += filesize($file);
        }abort_if($size > 40 * 1024 * 1024, 413);
        $dir = $root.'/'.$v['upload'];
        if (! is_dir($dir)) {
            abort_if(count(glob($root.'/*') ?: []) >= 4, 429);
            mkdir($dir, 0700);
        }
        $scope = hash('sha256', $request->user()->id.'|'.$request->user()->auth_version.'|'.$source.'|'.$v['total']);
        $meta = $dir.'/scope';
        if (! file_exists($meta)) {
            file_put_contents($meta, $scope, LOCK_EX);
        }abort_unless(hash_equals(file_get_contents($meta), $scope), 403);
        file_put_contents($dir.'/'.$v['index'], $v['data'], LOCK_EX);
        if ($v['index'] !== $v['total'] - 1) {
            return response()->json(['received' => $v['index']]);
        }
        try {
            $json = '';
            for ($i = 0; $i < $v['total']; $i++) {
                abort_unless(file_exists($dir.'/'.$i), 422);
                $json .= file_get_contents($dir.'/'.$i);
                abort_if(strlen($json) > 32 * 1024 * 1024, 413);
            }
            $body = json_decode($json, true);
            abort_unless(json_last_error() === JSON_ERROR_NONE, 422, 'Data unggahan tidak valid. Unggah ulang dokumen atau data.');
            abort_unless(is_array($body) && is_int($body['revision'] ?? null) && is_array($body['payload'] ?? null), 422);

            if ($source === 'document-files') {
                return response()->json(app(DocumentAttachmentService::class)->save($request->user(), $body['payload']));
            }
            if ($source === 'policy') {
                return response()->json(app(PolicyDocumentService::class)->save($request->user(), $body['revision'], $body['payload']));
            }

            return response()->json($this->workspaces->save($request->user(), $source, $body['revision'], $body['payload']));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                unlink($f);
            }rmdir($dir);
        }
    }

    public function backup(Request $request): StreamedResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = ['type' => 'silestari-laravel', 'schemaVersion' => 1, 'exportedAt' => now()->toIso8601String(), 'workspaces' => $this->workspaces->snapshot($request->user()), 'accounts' => User::all()->map(fn ($user) => $user->getAttributes())->all()];

        return response()->streamDownload(function () use ($data): void {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, 'SILESTARI-Laravel-cadangan-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private']);
    }
}
