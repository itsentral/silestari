<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        } if (! User::query()->exists()) {
            abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);

            return view('auth.setup');
        }

        return view('auth.login');
    }

    public function create(): View|RedirectResponse
    {
        if (! User::query()->exists()) {
            return redirect()->route('setup.create');
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $identifier = $request->validated('email');
        $column = str_contains($identifier, '@') ? 'email' : 'username';
        if (! Auth::attempt([$column => $identifier, 'password' => $request->validated('password'), 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'Username/email atau password salah, atau akun tidak aktif.']);
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->auth_version);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
