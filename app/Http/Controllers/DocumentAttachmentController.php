<?php

namespace App\Http\Controllers;

use App\Models\DocumentAttachment;
use App\Services\DocumentAttachmentService;
use App\Services\WorkspaceService;
use App\Support\WorkspaceNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentController extends Controller
{
    public function index(Request $request, string $kind): View
    {
        abort_unless(isset(DocumentAttachment::KINDS[$kind]), 404);
        $q = DocumentAttachment::where('kind', $kind);
        if (! $request->user()->isAdmin()) {
            $q->where('department', $request->user()->department);
        }
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        if ($search !== '') {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('number', 'like', '%'.$search.'%'));
        }
        $company = app(WorkspaceService::class)->workspace('iso')->payload['company'] ?? [];
        $logo = is_string($company['logo'] ?? null) && preg_match('/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/D', $company['logo']) ? $company['logo'] : asset('images/company-logo.png');

        return view('document-control.attachments', ['workspacePageKey' => 'documents', 'workspacePageTitle' => 'Document Control', 'workspaceCompany' => $company, 'workspaceLogo' => $logo, 'workspaceNavigation' => WorkspaceNavigation::sections($request->user()), 'kind' => $kind, 'label' => DocumentAttachment::KINDS[$kind], 'files' => $q->latest('id')->paginate(20)->withQueryString(), 'search' => $search]);
    }

    public function file(Request $request, DocumentAttachment $attachment): StreamedResponse
    {
        app(DocumentAttachmentService::class)->authorize($request->user(), $attachment);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN'], (! $request->boolean('download') && in_array($attachment->mime, ['application/pdf', 'image/png', 'image/jpeg'], true)) ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, DocumentAttachment $attachment): RedirectResponse
    {
        app(DocumentAttachmentService::class)->authorize($request->user(), $attachment);
        $attachment->delete();

        return redirect()->route('document-control.index', ['kind' => $attachment->kind])->with('status','Lampiran dihapus dari daftar dokumen.');
    }
}
