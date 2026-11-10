<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:4096']];
    }

    public function messages(): array
    {
        return ['email.required' => 'Isi username atau email.', 'email.email' => 'Alamat email tidak valid.', 'password.required' => 'Isi password.'];
    }
}
