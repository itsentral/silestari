<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasModule('communication') ?? false;
    }

    public function rules(): array
    {
        if ($this->routeIs('communication.store')) {
            return ['year' => 'required|integer|min:2000|max:2100', 'department' => 'required|string|max:120', 'template' => 'required|boolean'];
        }
        $rules = ['action' => ['required', Rule::in(['metadata', 'add_source', 'update_source', 'delete_source', 'add_material', 'update_material', 'delete_material', 'copy', 'sign', 'delete_evidence'])], 'revision' => 'required|integer|min:1'];

        return match ($this->input('action')) {
            'metadata' => [...$rules, 'number' => 'nullable|string|max:120', 'edition' => 'nullable|string|max:120', 'document_revision' => 'nullable|string|max:60', 'prepared' => 'required|integer|exists:users,id', 'reviewed' => 'nullable|integer|exists:users,id', 'approved' => 'nullable|integer|exists:users,id'],
            'add_source','update_source' => [...$rules, 'name' => 'required|string|max:2000', 'source_id' => 'nullable|required_if:action,update_source|uuid'],
            'delete_source' => [...$rules, 'source_id' => 'required|uuid'],
            'add_material','update_material' => [...$rules, 'material_id' => 'nullable|required_if:action,update_material|uuid', 'source_id' => 'required|uuid', 'title' => 'required|string|max:4000', 'criteria' => ['required', Rule::in(['Internal', 'Eksternal', 'Internal / Eksternal'])], ...array_fill_keys(['method', 'language', 'timing', 'audience', 'responsible', 'effectiveness', 'control_document'], 'nullable|string|max:4000')],
            'delete_material' => [...$rules, 'material_id' => 'required|uuid'],
            'delete_evidence' => [...$rules, 'evidence_id' => 'required|integer'],
            'copy' => $rules,
            'sign' => [...$rules, 'slot' => ['required', Rule::in(['prepared', 'reviewed', 'approved'])], 'password' => 'required|string|max:4096', 'signature' => 'required|string|max:600000'],
            default => $rules,
        };
    }
}
