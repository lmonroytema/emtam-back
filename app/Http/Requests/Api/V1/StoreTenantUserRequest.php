<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantUserRequest extends FormRequest
{
    public function rules(): array
    {
        $profiles = ['admin', 'director', 'recurso', 'recurso-visor'];

        return [
            'name' => ['required', 'string', 'min:1'],
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:6'],
            'language' => ['nullable', 'string', 'size:2'],
            'perfil' => ['required', Rule::in($profiles)],
        ];
    }
}
