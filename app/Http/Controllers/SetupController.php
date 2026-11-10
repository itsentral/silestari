<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupAdminRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

        return view('auth.setup');
    }

    public function store(SetupAdminRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = DB::transaction(function () use ($data): User {
            $installation = DB::table('app_installations')->where('id', 1)->lockForUpdate()->first();
            abort_if(! $installation || $installation->completed_at || User::query()->exists(), 403);
            $user = new User;
            $user->fill(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill(['role' => 'admin', 'is_active' => true, 'allowed_modules' => [], 'auth_version' => 1])->save();
            DB::table('app_installations')->where('id', 1)->update(['completed_at' => now()]);

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);

        return redirect()->route('dashboard')->with('status', 'Akun admin berhasil dibuat.');
    }
}
