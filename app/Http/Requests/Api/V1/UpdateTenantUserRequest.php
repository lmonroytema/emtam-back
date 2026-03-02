<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->route('userId');
        $profiles = ['admin', 'director', 'recurso', 'recurso-visor', 'auditor'];

        return [
            'name' => ['sometimes', 'string', 'min:1'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'language' => ['sometimes', 'nullable', 'string', 'size:2'],
            'perfil' => ['sometimes', Rule::in($profiles)],
        ];
    }
}
