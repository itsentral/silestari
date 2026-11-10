<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:4096'], 'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'confirmed', 'max:72']];
    }
}
