<?php

namespace App\Http\Requests\Api\V1;

use App\Services\LanguageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantLanguagesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'default_language' => ['required', Rule::in(LanguageService::SUPPORTED)],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['required', Rule::in(LanguageService::SUPPORTED)],
        ];
    }
}
