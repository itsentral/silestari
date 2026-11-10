<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcedureActionRequest;
use App\Http\Requests\SaveProcedureRequest;
use App\Models\ControlledDocument;
use App\Models\ProcedureRevision;
use App\Models\User;
use App\Services\DocumentControlService;
use App\Services\WorkspaceService;
use App\Support\WorkspaceNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentControlController extends Controller
{
    public function __construct(private DocumentControlService $workflow) {}

    private function shell(Request $request): array
    {
        $company = app(WorkspaceService::class)->workspace('iso')->payload['company'] ?? [];
        $logo = is_string($company['logo'] ?? null) && preg_match('/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/D', $company['logo']) ? $company['logo'] : asset('images/company-logo.png');

        return ['workspacePageKey' => 'documents', 'workspacePageTitle' => 'Document Control', 'workspaceCompany' => $company, 'workspaceLogo' => $logo, 'workspaceNavigation' => WorkspaceNavigation::sections($request->user())];
    }

    public function index(Request $request): View
    {
        $kind = (string) $request->query('kind', 'procedure');
        if ($kind !== 'procedure') {
            return app(DocumentAttachmentController::class)->index($request, $kind);
        }
        $status = (string) $request->query('status', 'draft');
        abort_unless(in_array($status, [...array_keys(ProcedureRevision::STATUSES), 'all', 'deletion'], true), 404);
        $base = $this->workflow->visible($request->user());
        $counts = [];
        foreach (ProcedureRevision::STATUSES as $key => $label) {
            $q = clone $base;
            $q->where('is_archived', false);
            $key === 'published' ? $q->whereNotNull('published_revision_id') : $q->whereHas('currentRevision', fn ($r) => $r->where('status', $key));
            $counts[$key] = $q->count();
        }$counts['deletion'] = (clone $base)->where('is_archived', true)->count();
        $q = clone $base;
        if ($status === 'deletion') {
            $q->where('is_archived', true);
        } else {
            $q->where('is_archived', false);
            if ($status === 'published') {
                $q->whereNotNull('published_revision_id');
            } elseif ($status !== 'all') {
                $q->whereHas('currentRevision', fn ($r) => $r->where('status', $status));
            }
        }
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        if ($search !== '') {
            $q->where(fn ($q) => $q->where('number', 'like', '%'.$search.'%')->orWhereHas('currentRevision', fn ($r) => $r->where('title', 'like', '%'.$search.'%')));
        }

        return view('document-control.index', [...$this->shell($request), 'documents' => $q->with(['currentRevision', 'publishedRevision'])->latest()->paginate(20)->withQueryString(), 'status' => $status, 'counts' => $counts, 'search' => $search, 'statuses' => ProcedureRevision::STATUSES]);
    }

    private function formData(Request $request, ?ControlledDocument $document): array
    {
        $people = User::where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => $u->hasModule('documents'));

        return [...$this->shell($request), 'document' => $document, 'revision' => $document?->currentRevision, 'people' => $people, 'sections' => ProcedureRevision::SECTIONS];
    }

    public function create(Request $request): View
    {
        return view('document-control.form', $this->formData($request, null));
    }

    public function store(SaveProcedureRequest $request): RedirectResponse
    {
        $doc = $this->workflow->save($request->user(), null, $request->validated());

        return redirect()->route('document-control.show', $doc)->with('status', 'Draft prosedur tersimpan. Lengkapi isi lalu TTD & ajukan review.');
    }

    public function edit(Request $request, ControlledDocument $document): View
    {
        $this->workflow->authorize($request->user(), $document);
        $document->load('currentRevision');
        abort_unless($this->workflow->editable($request->user(), $document), 403);

        return view('document-control.form', $this->formData($request, $document));
    }

    public function update(SaveProcedureRequest $request, ControlledDocument $document): RedirectResponse
    {
        $doc = $this->workflow->save($request->user(), $document, $request->validated());

        return redirect()->route('document-control.show', $doc)->with('status', 'Perubahan prosedur dan alasannya tersimpan dalam riwayat.');
    }

    public function show(Request $request, ControlledDocument $document): View
    {
        $this->workflow->authorize($request->user(), $document);
        $document->load(['creator', 'currentRevision.reviewer', 'currentRevision.approver', 'currentRevision.publisher', 'publishedRevision']);
        $revision = $request->filled('revision') ? $document->revisions()->where('version', $request->integer('revision'))->firstOrFail() : $document->currentRevision;
        $revision->load(['reviewer', 'approver', 'publisher']);
        $events = $document->events()->latest('id')->paginate(15)->withQueryString();
        $signatures = $revision->events()->where('cycle', $revision->cycle)->where('content_hash', $revision->content_hash)->whereIn('action', ['submit', 'review', 'approve', 'publish'])->orderBy('id')->get()->keyBy('action');

        return view('document-control.show', [...$this->shell($request), 'document' => $document, 'revision' => $revision, 'events' => $events, 'signatures' => $signatures, 'rejection' => $revision->status === 'correction' ? $revision->events()->where('cycle', $revision->cycle)->where('action', 'reject')->latest('id')->first() : null, 'sections' => ProcedureRevision::SECTIONS, 'editable' => $revision->id === $document->current_revision_id && $this->workflow->editable($request->user(), $document), 'isCurrent' => $revision->id === $document->current_revision_id, 'statuses' => ProcedureRevision::STATUSES, 'versions' => $document->revisions()->orderByDesc('version')->get(['id', 'version', 'status'])]);
    }

    public function action(ProcedureActionRequest $request, ControlledDocument $document): RedirectResponse
    {
        $doc = $this->workflow->act($request->user(), $document, $request->validated());

        return redirect()->route('document-control.show', $doc)->with('status', 'Tindakan dan TTD akun berhasil dicatat.');
    }

    public function published(Request $request, ControlledDocument $document): RedirectResponse
    {
        $this->workflow->authorize($request->user(), $document);
        abort_if($document->is_archived || ! $document->published_revision_id, 404);
        $revision = $document->publishedRevision;

        return redirect()->route('document-control.show', [$document, 'revision' => $revision->version, 'print' => $request->boolean('print') ? 1 : 0]);
    }
}
