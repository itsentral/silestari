<?php

namespace App\Http\Middleware;

use App\Models\CsmsAccount;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CsmsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = CsmsAccount::find($request->session()->get('csms_account_id'));
        abort_unless($actor && $actor->active && (int) $request->session()->get('csms_auth_version') === $actor->auth_version, 401, 'Silakan masuk ke portal CSMS.');
        if ($actor->internal_user_id) {
            $internal = $request->user();
            abort_unless($internal instanceof User && $internal->id === $actor->internal_user_id && $internal->hasModule('csms') && (int) $request->session()->get('auth_version') === $internal->auth_version, 403, 'Sesi atau akses CSMS akun internal sudah berubah.');
        }
        $request->setUserResolver(fn () => $actor);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-CSRF-TOKEN', csrf_token());

        return $response;
    }
}
