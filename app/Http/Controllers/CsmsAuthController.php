<?php

namespace App\Http\Controllers;

use App\Models\CsmsAccount;
use App\Services\CsmsPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CsmsAuthController extends Controller
{
    private function start(Request $r, CsmsAccount $u): void
    {
        $r->session()->regenerate();
        $r->session()->put(['csms_account_id' => $u->id, 'csms_auth_version' => $u->auth_version]);
        $r->setUserResolver(fn () => $u);
    }

    public function internal(Request $r): RedirectResponse
    {
        $internal = $r->user();
        abort_unless($internal->hasModule('csms'), 403);
        $u = CsmsAccount::firstOrNew(['internal_user_id' => $internal->id]);
        if (! $u->exists) {
            $u->fill(['name' => $internal->name, 'username' => $internal->username ?? $internal->email, 'password' => Str::random(60), 'roles' => [$internal->isAdmin() ? 'admin' : 'pengadaan'], 'active' => true, 'auth_version' => 1]);
        } else {
            abort_unless($u->active, 403);
            $u->name = $internal->name;
            if ($internal->isAdmin()) {
                $u->roles = ['admin'];
            } elseif (in_array('admin', $u->roles ?? [], true)) {
                $u->roles = ['pengadaan'];
            }
        }
        $u->save();
        $this->start($r, $u);
        CsmsPortal::audit('internal_login');

        return redirect()->route('csms.portal');
    }

    public function login(Request $r): JsonResponse
    {
        $d = $r->validate(['username' => 'required|email|max:255', 'password' => 'required|string|max:4096']);
        $u = CsmsAccount::where('email', mb_strtolower(trim($d['username'])))->whereNull('internal_user_id')->first();
        if (! $u || ! $u->active || ! Hash::check($d['password'], $u->password)) {
            throw ValidationException::withMessages(['username' => 'Email atau password salah, atau akun CSMS tidak aktif.']);
        }
        $this->start($r, $u);
        CsmsPortal::audit('login');

        return response()->json($u->publicData())->header('X-CSRF-TOKEN', csrf_token());
    }

    public function register(Request $r): JsonResponse
    {
        $d = $r->validate(['username' => 'required|email|max:255|unique:csms_accounts,email', 'name' => 'required|string|max:255', 'password' => CsmsPortal::passwordRules()]);
        $u = CsmsAccount::create(['name' => $d['name'], 'email' => mb_strtolower($d['username']), 'password' => $d['password'], 'roles' => ['vendor'], 'active' => true, 'auth_version' => 1]);
        $this->start($r, $u);
        CsmsPortal::audit('register');

        return response()->json($u->publicData(), 201)->header('X-CSRF-TOKEN', csrf_token());
    }

    public function logout(Request $r): JsonResponse
    {
        CsmsPortal::audit('logout');
        $r->session()->forget(['csms_account_id', 'csms_auth_version']);
        $r->session()->regenerate();
        $r->session()->regenerateToken();

        return response()->json(['ok' => true])->header('X-CSRF-TOKEN', csrf_token());
    }

    public function password(Request $r): JsonResponse
    {
        abort_if($r->user()->internal_user_id, 422, 'Gunakan menu Ganti password di SILESTARI untuk akun internal yang ditautkan.');
        $d = $r->validate(['current_password' => 'required|string', 'password' => CsmsPortal::passwordRules()]);
        if (! Hash::check($d['current_password'], $r->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini salah.']);
        }
        $r->user()->forceFill(['password' => $d['password'], 'auth_version' => $r->user()->auth_version + 1])->save();
        $this->start($r, $r->user());
        CsmsPortal::audit('password_changed');

        return response()->json(['ok' => true])->header('X-CSRF-TOKEN',csrf_token());
    }
}
