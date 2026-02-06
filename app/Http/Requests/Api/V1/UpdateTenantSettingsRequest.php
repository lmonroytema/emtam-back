<?php

namespace App\Http\Requests\Api\V1;

use App\Services\LanguageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1'],
            'address' => ['sometimes', 'nullable', 'string'],
            'jurisdiction' => ['sometimes', 'nullable', 'string'],
            'brand_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'theme' => ['sometimes', 'nullable', 'array'],
            'notifications_production_mode' => ['sometimes', 'boolean'],
            'test_notification_emails' => ['sometimes', 'nullable', 'array'],
            'test_notification_emails.*' => ['required', 'email'],
            'test_notification_whatsapp_numbers' => ['sometimes', 'nullable', 'array'],
            'test_notification_whatsapp_numbers.*' => ['required', 'string', 'regex:/^\+?\d{8,15}$/'],
            'gps_min_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'gps_max_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'gps_min_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'gps_max_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'default_language' => ['sometimes', Rule::in(LanguageService::SUPPORTED)],
            'languages' => ['sometimes', 'array', 'min:1'],
            'languages.*' => ['required', Rule::in(LanguageService::SUPPORTED)],
        ];
    }
}
