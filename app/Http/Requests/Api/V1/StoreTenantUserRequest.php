<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantUserRequest extends FormRequest
{
    public function rules(): array
    {
        $profiles = ['admin', 'tenant_admin', 'director', 'recurso', 'recurso-visor', 'auditor'];

        return [
            'persona_id' => ['required', 'string', 'min:1'],
            'password' => ['required', 'string', 'min:6'],
            'language' => ['nullable', 'string', 'size:2'],
            'perfil' => ['required', Rule::in($profiles)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
