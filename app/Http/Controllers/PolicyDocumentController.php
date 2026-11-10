<?php

namespace App\Http\Controllers;

use App\Models\PolicyDocument;
use App\Services\WorkspaceService;
use App\Support\WorkspaceNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $newest = PolicyDocument::withTrashed()->orderByDesc('version')->first();
        $latest = $newest && ! $newest->trashed() ? $newest : null;
        $selected = $request->filled('version') ? PolicyDocument::where('version', $request->integer('version'))->firstOrFail() : $latest;

        $company = app(WorkspaceService::class)->workspace('iso')->payload['company'] ?? [];
        $logo = is_string($company['logo'] ?? null) && preg_match('/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/D', $company['logo']) ? $company['logo'] : asset('images/company-logo.png');

        return view('policy.index', ['workspaceCompany' => $company, 'workspaceLogo' => $logo, 'workspaceNavigation' => WorkspaceNavigation::sections($request->user()), 'latest' => $latest, 'revision' => $newest?->version ?? 0, 'selected' => $selected, 'history' => PolicyDocument::orderByDesc('version')->paginate(20)]);
    }

    public function file(Request $request, PolicyDocument $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, ['Content-Type' => $document->mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN'], $request->boolean('download') ? 'attachment' : 'inline');
    }

    public function destroy(Request $request, PolicyDocument $document): RedirectResponse
    {
        DB::transaction(function () use ($request, $document): void {
            DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
            $document = PolicyDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->forceFill(['deleted_by' => $request->user()->id, 'deleted_by_name' => $request->user()->name])->save();
            $document->delete();
        });

        return redirect()->route('policy.index')->with('status', 'Dokumen telah dihapus dari tampilan dan daftar riwayat.');
    }
}
