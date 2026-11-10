<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommunicationRequest;
use App\Models\CommunicationEvidence;
use App\Models\CommunicationMatrix;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\WorkspaceService;
use App\Support\WorkspaceNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunicationController extends Controller
{
    public function __construct(private CommunicationService $service) {}

    private function shell(Request $request): array
    {
        $company = app(WorkspaceService::class)->workspace('iso')->payload['company'] ?? [];
        $logo = is_string($company['logo'] ?? null) && preg_match('/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/D', $company['logo']) ? $company['logo'] : asset('images/company-logo.png');

        return ['workspacePageKey' => 'communication', 'workspacePageTitle' => 'Komunikasi, Konsultasi dan Partisipasi', 'workspaceCompany' => $company, 'workspaceLogo' => $logo, 'workspaceNavigation' => WorkspaceNavigation::sections($request->user())];
    }

    public function index(Request $request): View
    {
        return view('communication.index', [...$this->shell($request), 'matrices' => $this->service->visible($request->user())->orderByDesc('year')->orderBy('department')->paginate(20)]);
    }

    public function store(CommunicationRequest $request): RedirectResponse
    {
        return redirect()->route('communication.show', $this->service->create($request->user(), $request->validated()))->with('status', 'Matriks komunikasi tersimpan.');
    }

    public function show(Request $request, CommunicationMatrix $matrix): View
    {
        $this->service->authorize($request->user(), $matrix);
        $people = User::where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => $u->hasModule('communication') && ($u->isAdmin() || $u->department === $matrix->department));

        return view('communication.show', [...$this->shell($request), 'matrix' => $matrix, 'payload' => $matrix->payload, 'fields' => CommunicationService::FIELDS, 'slots' => CommunicationService::SLOTS, 'people' => $people, 'evidence' => $matrix->evidence()->orderBy('id')->get()->groupBy('material_id'), 'events' => $matrix->events()->latest('id')->paginate(12)->withQueryString()]);
    }

    public function action(CommunicationRequest $request, CommunicationMatrix $matrix): JsonResponse
    {
        $result = $this->service->act($request->user(), $matrix, $request->validated());

        return response()->json(['redirect' => route('communication.show', $result), 'revision' => $result->revision]);
    }

    public function file(Request $request, CommunicationMatrix $matrix, CommunicationEvidence $evidence): StreamedResponse
    {
        $this->service->authorize($request->user(), $matrix);
        abort_unless($evidence->communication_matrix_id === $matrix->id, 404);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        return Storage::disk('local')->response($evidence->path, $evidence->original_name, ['Content-Type' => $evidence->mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'; sandbox"], 'inline');
    }

    public function chunks(Request $request, CommunicationMatrix $matrix): JsonResponse
    {
        $this->service->authorize($request->user(), $matrix);
        $v = $request->validate(['upload' => 'required|uuid', 'index' => 'required|integer|min:0|max:119', 'total' => 'required|integer|min:1|max:120', 'data' => 'required|string|max:150000', 'material_id' => 'required|uuid', 'revision' => 'required|integer|min:1']);
        abort_if($v['index'] >= $v['total'], 422);
        $root = storage_path('app/private/communication-uploads/'.$request->user()->id);
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        foreach (glob($root.'/*') ?: [] as $old) {
            if (is_dir($old) && filemtime($old) < time() - 3600) {
                foreach (glob($old.'/*') ?: [] as $f) {
                    unlink($f);
                } rmdir($old);
            }
        }
        $size = 0;
        foreach (glob($root.'/*/*') ?: [] as $f) {
            $size += filesize($f);
        } abort_if($size > 40 * 1024 * 1024, 413);
        $dir = $root.'/'.$v['upload'];
        if (! is_dir($dir)) {
            abort_if(count(glob($root.'/*') ?: []) >= 4, 429);
            mkdir($dir, 0700);
        }
        $scope = hash('sha256', implode('|', [$request->user()->id, $request->user()->auth_version, $matrix->id, $v['material_id'], $v['revision'], $v['total']]));
        $meta = $dir.'/scope';
        if (! file_exists($meta)) {
            file_put_contents($meta, $scope, LOCK_EX);
        } abort_unless(hash_equals(file_get_contents($meta), $scope), 403);
        file_put_contents($dir.'/'.$v['index'], $v['data'], LOCK_EX);
        if ($v['index'] !== $v['total'] - 1) {
            return response()->json(['received' => $v['index']]);
        }
        try {
            $json = '';
            for ($i = 0; $i < $v['total']; $i++) {
                abort_unless(file_exists($dir.'/'.$i), 422, 'Bagian unggahan belum lengkap.');
                $json .= file_get_contents($dir.'/'.$i);
                abort_if(strlen($json) > 15000000, 413);
            }
            $data = json_decode($json, true);
            abort_unless(is_array($data), 422);
            $data['material_id'] = $v['material_id'];
            $data['revision'] = $v['revision'];
            $result = $this->service->attach($request->user(), $matrix, $data);

            return response()->json(['redirect' => route('communication.show', $result), 'revision' => $result->revision]);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                unlink($f);
            } rmdir($dir);
        }
    }
}
