<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\ModuleRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => filled($this->input('email')) ? mb_strtolower(trim((string) $this->input('email'))) : null, 'username' => filled($this->input('username')) ? mb_strtolower(trim((string) $this->input('username'))) : null]);
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return ['name' => ['required', 'string', 'max:150'], 'email' => ['nullable', 'required_without:username', 'email', 'max:255', Rule::unique('users')->ignore($user instanceof User ? $user->id : null)], 'username' => ['nullable', 'required_without:email', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users')->ignore($user instanceof User ? $user->id : null)], 'role' => ['required', Rule::in(['admin', 'user'])], 'is_active' => ['required', 'boolean'], 'department' => ['required_if:role,user', 'nullable', 'string', 'max:150'], 'allowed_modules' => ['nullable', 'array'], 'allowed_modules.*' => ['string', 'distinct', Rule::in(array_keys(ModuleRegistry::all()))], 'password' => [$user ? 'nullable' : 'required', 'string', 'confirmed', 'max:72', Password::min(12)->letters()->numbers(), function (string $attribute, mixed $value, \Closure $fail): void {
            if (strlen((string) $value) > 72) {
                $fail('Password maksimal 72 byte.');
            }
        }]];
    }

    public function messages(): array
    {
        return ['department.required_if' => 'Pilih departemen untuk akun pengguna.', 'password.confirmed' => 'Konfirmasi password tidak sama.', 'allowed_modules.*.in' => 'Modul yang dipilih tidak tersedia.'];
    }
}
