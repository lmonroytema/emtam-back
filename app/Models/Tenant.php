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
        'test_notification_emails',
        'test_notification_whatsapp_numbers',
    ];

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'notifications_production_mode' => 'boolean',
            'test_notification_emails' => 'array',
            'test_notification_whatsapp_numbers' => 'array',
        ];
    }

    public function languages(): HasMany
    {
        return $this->hasMany(TenantLanguage::class, 'tenant_id', 'tenant_id');
    }
}
