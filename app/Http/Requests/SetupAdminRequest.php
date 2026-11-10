<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetupAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->ip(), ['127.0.0.1', '::1'], true) && ! User::query()->exists();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(12)->letters()->numbers(), function (string $attribute, mixed $value, \Closure $fail): void {
            if (strlen((string) $value) > 72) {
                $fail('Password maksimal 72 byte.');
            }
        }]];
    }

    public function messages(): array
    {
        return ['name.required' => 'Isi nama admin.', 'email.required' => 'Isi alamat email.', 'password.confirmed' => 'Konfirmasi password tidak sama.', 'password.min' => 'Password minimal 12 karakter.'];
    }
}
