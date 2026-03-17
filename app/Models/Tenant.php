<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'name',
        'default_language',
        'brand_color',
        'theme',
        'logo_path',
        'address',
        'jurisdiction',
        'gps_min_lat',
        'gps_max_lat',
        'gps_min_lng',
        'gps_max_lng',
        'notifications_production_mode',
        'notifications_email_enabled',
        'test_notification_emails',
        'test_notification_whatsapp_numbers',
        'notifications_message_real',
        'notifications_message_simulacrum',
        'notifications_message_phase2',
        'notifications_include_credentials',
        'two_factor_enabled',
        'director_activation_code_hash',
        'director_activation_code_enabled',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'notifications_production_mode' => 'boolean',
            'notifications_email_enabled' => 'boolean',
            'test_notification_emails' => 'array',
            'test_notification_whatsapp_numbers' => 'array',
            'notifications_include_credentials' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'director_activation_code_enabled' => 'boolean',
        ];
    }

    public function languages(): HasMany
    {
        return $this->hasMany(TenantLanguage::class, 'tenant_id', 'tenant_id');
    }
}
