<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProcedureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasModule('documents') ?? false;
    }

    public function rules(): array
    {
        $doc = $this->route('document');

        return ['number' => ['required', 'string', 'max:100', Rule::unique('controlled_documents', 'number')->ignore($doc?->id)], 'department' => 'required|string|max:150', 'title' => 'required|string|max:200', 'process_group' => ['required', Rule::in(['Manajemen', 'Realisasi', 'Pendukung'])], 'reviewer_id' => 'required|integer|exists:users,id', 'approver_id' => 'required|integer|exists:users,id', 'publisher_id' => 'required|integer|exists:users,id', 'sections' => 'required|array:purpose,scope,definitions,references,process_risks,flowchart,details,related_documents', 'sections.*' => 'nullable|string|max:30000', 'diagram_image' => 'nullable|string|max:750000', 'change_reason' => 'required|string|min:3|max:3000', 'lock_version' => [$doc ? 'required' : 'nullable', 'integer', 'min:1']];
    }
}
