<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->route('userId');
        $profiles = ['admin', 'tenant_admin', 'director', 'recurso', 'recurso-visor', 'auditor'];

        return [
            'name' => ['sometimes', 'string', 'min:1'],
            'persona_id' => ['sometimes', 'string', 'min:1'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'language' => ['sometimes', 'nullable', 'string', 'size:2'],
            'perfil' => ['sometimes', Rule::in($profiles)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
