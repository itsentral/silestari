<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcedureActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasModule('documents') ?? false;
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['submit', 'review', 'approve', 'publish', 'reject', 'revise', 'archive'])], 'lock_version' => 'required|integer|min:1', 'reason' => 'nullable|string|max:3000', 'signature' => 'nullable|string|max:600000', 'password' => 'nullable|string|max:4096'];
    }
}
