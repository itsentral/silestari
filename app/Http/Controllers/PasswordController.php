<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password');
    }

    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        $identifier = $user->username ? ['username' => $user->username] : ['email' => $user->email];
        if (! Auth::validate([...$identifier, 'password' => $request->validated('current_password'), 'is_active' => true])) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini salah.']);
        }
        if (strlen($request->validated('password')) > 72) {
            throw ValidationException::withMessages(['password' => 'Password maksimal 72 byte.']);
        }
        $user->forceFill(['password' => $request->validated('password'), 'legacy_password_hash' => null, 'legacy_password_salt' => null, 'auth_version' => $user->auth_version + 1])->save();
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);

        return redirect()->route('dashboard')->with('status', 'Password berhasil diperbarui.');
    }
}
