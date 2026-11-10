<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetForgottenPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => 'required|email|max:255', 'token' => 'required|string|max:255', 'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->numbers(), 'max:72', function (string $attribute, mixed $value, \Closure $fail): void {
            if (strlen((string) $value) > 72) {
                $fail('Password maksimal 72 byte.');
            }
        }]];
    }

    public function messages(): array
    {
        return ['password.confirmed' => 'Konfirmasi password tidak sama.'];
    }
}
