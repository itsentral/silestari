<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetForgottenPasswordRequest;
use App\Models\User;
use App\Notifications\ResetSilestariPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public const GENERIC_STATUS = 'Jika email terdaftar dan akun aktif, tautan reset akan dikirim. Periksa kotak masuk dan folder spam.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    private function ready(): bool
    {
        $url = (string) config('password-recovery.url');
        $host = (string) config('mail.mailers.smtp.host');
        $from = (string) config('mail.from.address');

        return (bool) config('password-recovery.enabled') && config('mail.default') === 'smtp' && $host !== '' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true) && filter_var($from, FILTER_VALIDATE_EMAIL) !== false && ! str_ends_with(strtolower($from), '@example.com') && filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $email = mb_strtolower(trim($data['email']));
        if (! $this->ready()) {
            return back()->withInput(['email' => $email])->withErrors(['email' => 'Pengiriman email reset belum diaktifkan. Admin perlu mengonfigurasi layanan SMTP terlebih dahulu.']);
        }
        try {
            Password::broker()->sendResetLink(['email' => $email, 'is_active' => true], function (User $user, string $token): void {
                $user->notify(new ResetSilestariPassword($token));
            });
        } catch (\Throwable $error) {
            DB::table(config('auth.passwords.users.table'))->where('email', $email)->delete();
            Log::warning('Pengiriman email reset SILESTARI gagal.', ['exception_type' => $error::class]);
        }

        return back()->with('status', self::GENERIC_STATUS);
    }

    public function reset(Request $request, string $token): View
    {
        $data = $request->validate(['email' => 'nullable|email|max:255']);

        return view('auth.reset-password', ['token' => $token, 'email' => $data['email'] ?? '']);
    }

    public function update(ResetForgottenPasswordRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $status = DB::transaction(function () use ($data): string {
            User::where('email', $data['email'])->lockForUpdate()->first();

            return Password::broker()->reset([...$data, 'is_active' => true], function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'legacy_password_hash' => null, 'legacy_password_salt' => null, 'auth_version' => $user->auth_version + 1, 'remember_token' => null])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Tautan reset tidak valid atau telah kedaluwarsa. Ajukan tautan baru melalui Lupa password.']);
        }
        $request->session()->regenerate();

        return redirect()->route('login')->with('status','Password berhasil diperbarui. Silakan masuk dengan password baru.');
    }
}
