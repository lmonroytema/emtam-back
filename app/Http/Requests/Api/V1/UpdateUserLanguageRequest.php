<?php

namespace App\Http\Requests\Api\V1;

use App\Services\LanguageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserLanguageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'language' => ['required', Rule::in(LanguageService::SUPPORTED)],
        ];
    }
}
