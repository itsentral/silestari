<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveUserRequest;
use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', ['users' => User::query()->orderBy('name')->orderBy('id')->paginate(20), 'modules' => ModuleRegistry::all()]);
    }

    public function create(): View
    {
        $user = new User;
        $user->role = 'user';

        return view('users.form', ['account' => $user, 'modules' => ModuleRegistry::all()]);
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $this->persist(new User, $request);

        return redirect()->route('users.index')->with('status', 'Akun berhasil dibuat.');
    }

    public function edit(User $user): View
    {
        return view('users.form', ['account' => $user, 'modules' => ModuleRegistry::all()]);
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $this->persist($user, $request);

        return redirect()->route('users.index')->with('status', 'Akun dan hak akses diperbarui. Pengguna perlu masuk kembali.');
    }

    private function persist(User $user, SaveUserRequest $request): void
    {
        $data = $request->validated();
        if ($user->exists && $user->is($request->user()) && ($data['role'] !== 'admin' || ! (bool) $data['is_active'])) {
            throw ValidationException::withMessages(['role' => 'Admin yang sedang masuk tidak dapat menonaktifkan atau menurunkan perannya sendiri.']);
        }
        DB::transaction(function () use ($user, $data): void {
            $user->fill(['name' => $data['name'], 'email' => $data['email'] ?? null]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $user->legacy_password_hash = null;
                $user->legacy_password_salt = null;
            }
            $user->forceFill(['username' => $data['username'] ?? null, 'role' => $data['role'], 'department' => $data['role'] === 'user' ? $data['department'] : ($data['department'] ?? null), 'is_active' => (bool) $data['is_active'], 'allowed_modules' => $data['role'] === 'admin' ? [] : ($data['allowed_modules'] ?? []), 'auth_version' => ($user->auth_version ?? 0) + 1, 'remember_token' => null])->save();
        });
        if ($user->is($request->user())) {
            $request->session()->put('auth_version', $user->auth_version);
        }
    }
}
