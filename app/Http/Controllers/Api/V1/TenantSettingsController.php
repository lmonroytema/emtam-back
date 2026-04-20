<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantSettingsRequest;
use App\Models\Tenant;
use App\Models\TenantLanguage;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es', 'timezone' => 'Europe/Madrid'],
        );

        $this->ensureDefaultLanguageEnabled($tenant);

        $enabled = TenantLanguage::query()
            ->where('tenant_id', $tenant->tenant_id)
            ->where('is_active', true)
            ->pluck('language_code')
            ->values()
            ->all();

        $logoUrl = $this->tenantLogoUrl($tenant);

        return response()->json([
            'tenant' => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'address' => $tenant->address,
                'jurisdiction' => $tenant->jurisdiction,
                'brand_color' => $tenant->brand_color,
                'theme' => $tenant->theme,
                'notifications_production_mode' => (bool) ($tenant->notifications_production_mode ?? false),
                'notifications_email_enabled' => (bool) ($tenant->notifications_email_enabled ?? false),
                'notifications_sms_enabled' => (bool) ($tenant->notifications_sms_enabled ?? false),
                'notifications_channel' => in_array((string) ($tenant->notifications_channel ?? 'email'), ['email', 'whatsapp', 'both', 'email_sms'], true)
                    ? (string) $tenant->notifications_channel
                    : 'email',
                'test_notification_emails' => is_array($tenant->test_notification_emails) ? array_values($tenant->test_notification_emails) : [],
                'test_notification_whatsapp_numbers' => is_array($tenant->test_notification_whatsapp_numbers) ? array_values($tenant->test_notification_whatsapp_numbers) : [],
                'test_notification_sms_numbers' => is_array($tenant->test_notification_sms_numbers) ? array_values($tenant->test_notification_sms_numbers) : [],
                'notifications_message_real' => $tenant->notifications_message_real,
                'notifications_message_simulacrum' => $tenant->notifications_message_simulacrum,
                'notifications_message_phase2' => $tenant->notifications_message_phase2,
                'notifications_include_credentials' => (bool) ($tenant->notifications_include_credentials ?? false),
                'two_factor_enabled' => (bool) ($tenant->two_factor_enabled ?? false),
                'director_activation_code_enabled' => (bool) ($tenant->director_activation_code_enabled ?? false),
                'director_activation_code_configured' => (bool) ($tenant->director_activation_code_hash ?? null),
                'logo_path' => $tenant->logo_path,
                'logo_url' => $logoUrl,
                'gps_min_lat' => $tenant->gps_min_lat,
                'gps_max_lat' => $tenant->gps_max_lat,
                'gps_min_lng' => $tenant->gps_min_lng,
                'gps_max_lng' => $tenant->gps_max_lng,
                'default_language' => $tenant->default_language,
                'timezone' => $tenant->timezone ?: 'Europe/Madrid',
                'conformacion_tiempo_limite' => $tenant->conformacion_tiempo_limite ?? 0,
                'languages' => $enabled,
            ],
        ]);
    }

    public function update(UpdateTenantSettingsRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es', 'timezone' => 'Europe/Madrid'],
        );

        $data = $request->validated();

        $testEmails = null;
        if (array_key_exists('test_notification_emails', $data)) {
            $raw = $data['test_notification_emails'];
            $rawArr = is_array($raw) ? $raw : [];
            $emails = [];
            foreach ($rawArr as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '') {
                    $emails[] = $e;
                }
            }
            $testEmails = array_values(array_unique($emails));
        }

        $testWhatsappNumbers = null;
        if (array_key_exists('test_notification_whatsapp_numbers', $data)) {
            $raw = $data['test_notification_whatsapp_numbers'];
            $rawArr = is_array($raw) ? $raw : [];
            $numbers = [];
            foreach ($rawArr as $n) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $n = preg_replace('/[()\-\.\s]+/', '', $n) ?? '';
                if (str_starts_with($n, '+')) {
                    $digits = preg_replace('/\D+/', '', substr($n, 1)) ?? '';
                    $n = $digits !== '' ? '+'.$digits : '';
                } else {
                    $n = preg_replace('/\D+/', '', $n) ?? '';
                }
                if ($n !== '') {
                    $numbers[] = $n;
                }
            }
            $testWhatsappNumbers = array_values(array_unique($numbers));
        }
        $testSmsNumbers = null;
        if (array_key_exists('test_notification_sms_numbers', $data)) {
            $raw = $data['test_notification_sms_numbers'];
            $rawArr = is_array($raw) ? $raw : [];
            $numbers = [];
            foreach ($rawArr as $n) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $n = preg_replace('/[()\-\.\s]+/', '', $n) ?? '';
                if (str_starts_with($n, '+')) {
                    $digits = preg_replace('/\D+/', '', substr($n, 1)) ?? '';
                    $n = $digits !== '' ? '+'.$digits : '';
                } else {
                    $n = preg_replace('/\D+/', '', $n) ?? '';
                }
                if ($n !== '') {
                    $numbers[] = $n;
                }
            }
            $testSmsNumbers = array_values(array_unique($numbers));
        }
        $notificationsChannel = array_key_exists('notifications_channel', $data)
            ? strtolower(trim((string) $data['notifications_channel']))
            : strtolower(trim((string) ($tenant->notifications_channel ?? 'email')));
        if (! in_array($notificationsChannel, ['email', 'whatsapp', 'both', 'email_sms'], true)) {
            $notificationsChannel = 'email';
        }
        $productionMode = array_key_exists('notifications_production_mode', $data)
            ? (bool) $data['notifications_production_mode']
            : (bool) ($tenant->notifications_production_mode ?? false);
        $emailEnabled = array_key_exists('notifications_email_enabled', $data)
            ? (bool) $data['notifications_email_enabled']
            : (bool) ($tenant->notifications_email_enabled ?? false);
        $smsEnabled = array_key_exists('notifications_sms_enabled', $data)
            ? (bool) $data['notifications_sms_enabled']
            : (bool) ($tenant->notifications_sms_enabled ?? false);
        $effectiveTestEmails = $testEmails !== null ? $testEmails : (is_array($tenant->test_notification_emails) ? array_values($tenant->test_notification_emails) : []);
        $effectiveTestWhatsapp = $testWhatsappNumbers !== null ? $testWhatsappNumbers : (is_array($tenant->test_notification_whatsapp_numbers) ? array_values($tenant->test_notification_whatsapp_numbers) : []);
        $effectiveTestSms = $testSmsNumbers !== null ? $testSmsNumbers : (is_array($tenant->test_notification_sms_numbers) ? array_values($tenant->test_notification_sms_numbers) : []);
        $channelUsesEmail = in_array($notificationsChannel, ['email', 'both', 'email_sms'], true);
        $channelUsesWhatsapp = in_array($notificationsChannel, ['whatsapp', 'both'], true);
        if (! $productionMode && $channelUsesEmail && $emailEnabled && count($effectiveTestEmails) === 0) {
            return response()->json([
                'message' => 'En modo PRUEBA con canal correo/both debes configurar al menos un correo de prueba.',
                'errors' => ['test_notification_emails' => ['At least one test email is required in test mode.']],
            ], 422);
        }
        if (! $productionMode && $channelUsesWhatsapp && count($effectiveTestWhatsapp) === 0) {
            return response()->json([
                'message' => 'En modo PRUEBA con canal whatsapp/both debes configurar al menos un WhatsApp de prueba.',
                'errors' => ['test_notification_whatsapp_numbers' => ['At least one WhatsApp test number is required in test mode.']],
            ], 422);
        }
        if (! $productionMode && $smsEnabled && count($effectiveTestSms) === 0) {
            return response()->json([
                'message' => 'En modo PRUEBA con SMS habilitado debes configurar al menos un número SMS de prueba.',
                'errors' => ['test_notification_sms_numbers' => ['At least one SMS test number is required in test mode.']],
            ], 422);
        }

        $messageReal = null;
        if (array_key_exists('notifications_message_real', $data)) {
            $value = $data['notifications_message_real'];
            $messageReal = is_string($value) ? trim($value) : null;
        }
        $messageSimulacrum = null;
        if (array_key_exists('notifications_message_simulacrum', $data)) {
            $value = $data['notifications_message_simulacrum'];
            $messageSimulacrum = is_string($value) ? trim($value) : null;
        }
        $messagePhase2 = null;
        if (array_key_exists('notifications_message_phase2', $data)) {
            $value = $data['notifications_message_phase2'];
            $messagePhase2 = is_string($value) ? trim($value) : null;
        }

        $activationCodeEnabled = array_key_exists('director_activation_code_enabled', $data)
            ? (bool) $data['director_activation_code_enabled']
            : (bool) ($tenant->director_activation_code_enabled ?? false);
        $activationCodeHash = $tenant->director_activation_code_hash;
        $activationCodeRaw = null;
        if (array_key_exists('director_activation_code', $data)) {
            $activationCodeRaw = trim((string) $data['director_activation_code']);
            if ($activationCodeRaw !== '') {
                $activationCodeHash = Hash::make($activationCodeRaw);
                if (! array_key_exists('director_activation_code_enabled', $data)) {
                    $activationCodeEnabled = true;
                }
            } else {
                $activationCodeHash = null;
            }
        }
        if (! $activationCodeEnabled) {
            $activationCodeHash = null;
        }

        $tenant->forceFill([
            'name' => array_key_exists('name', $data) ? (string) $data['name'] : $tenant->name,
            'address' => $data['address'] ?? $tenant->address,
            'jurisdiction' => $data['jurisdiction'] ?? $tenant->jurisdiction,
            'brand_color' => $data['brand_color'] ?? $tenant->brand_color,
            'theme' => array_key_exists('theme', $data) ? ($data['theme'] ?: null) : $tenant->theme,
            'notifications_production_mode' => $productionMode,
            'notifications_email_enabled' => $emailEnabled,
            'notifications_sms_enabled' => $smsEnabled,
            'notifications_channel' => $notificationsChannel,
            'test_notification_emails' => $effectiveTestEmails,
            'test_notification_whatsapp_numbers' => $effectiveTestWhatsapp,
            'test_notification_sms_numbers' => $effectiveTestSms,
            'notifications_message_real' => array_key_exists('notifications_message_real', $data)
                ? $messageReal
                : $tenant->notifications_message_real,
            'notifications_message_simulacrum' => array_key_exists('notifications_message_simulacrum', $data)
                ? $messageSimulacrum
                : $tenant->notifications_message_simulacrum,
            'notifications_message_phase2' => array_key_exists('notifications_message_phase2', $data)
                ? $messagePhase2
                : $tenant->notifications_message_phase2,
            'notifications_include_credentials' => array_key_exists('notifications_include_credentials', $data)
                ? (bool) $data['notifications_include_credentials']
                : (bool) ($tenant->notifications_include_credentials ?? false),
            'two_factor_enabled' => array_key_exists('two_factor_enabled', $data)
                ? (bool) $data['two_factor_enabled']
                : (bool) ($tenant->two_factor_enabled ?? false),
            'director_activation_code_enabled' => $activationCodeEnabled,
            'director_activation_code_hash' => $activationCodeHash,
            'gps_min_lat' => $data['gps_min_lat'] ?? $tenant->gps_min_lat,
            'gps_max_lat' => $data['gps_max_lat'] ?? $tenant->gps_max_lat,
            'gps_max_lat' => $data['gps_max_lat'] ?? $tenant->gps_max_lat,
            'gps_min_lng' => $data['gps_min_lng'] ?? $tenant->gps_min_lng,
            'gps_max_lng' => $data['gps_max_lng'] ?? $tenant->gps_max_lng,
            'default_language' => $data['default_language'] ?? $tenant->default_language,
            'timezone' => array_key_exists('timezone', $data)
                ? (string) $data['timezone']
                : ($tenant->timezone ?: 'Europe/Madrid'),
            'conformacion_tiempo_limite' => array_key_exists('conformacion_tiempo_limite', $data)
                ? (int) $data['conformacion_tiempo_limite']
                : ($tenant->conformacion_tiempo_limite ?? 0),
        ])->save();

        $this->ensureDefaultLanguageEnabled($tenant);

        if (array_key_exists('languages', $data) || array_key_exists('default_language', $data)) {
            $languages = array_values(array_unique($data['languages'] ?? []));
            $default = (string) ($data['default_language'] ?? $tenant->default_language);

            if (! in_array($default, $languages, true)) {
                $languages[] = $default;
            }

            $desired = array_fill_keys($languages, true);

            $existing = TenantLanguage::query()
                ->where('tenant_id', $tenant->tenant_id)
                ->get()
                ->keyBy('language_code');

            foreach ($desired as $code => $active) {
                TenantLanguage::query()->updateOrCreate(
                    ['tenant_id' => $tenant->tenant_id, 'language_code' => $code],
                    ['is_active' => true],
                );
            }

            $toDeactivate = array_diff($existing->keys()->all(), array_keys($desired));

            if (! empty($toDeactivate)) {
                TenantLanguage::query()
                    ->where('tenant_id', $tenant->tenant_id)
                    ->whereIn('language_code', $toDeactivate)
                    ->update(['is_active' => false]);
            }
        }

        $enabled = TenantLanguage::query()
            ->where('tenant_id', $tenant->tenant_id)
            ->where('is_active', true)
            ->pluck('language_code')
            ->values()
            ->all();

        $logoUrl = $this->tenantLogoUrl($tenant);

        return response()->json([
            'message' => 'OK',
            'tenant' => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'address' => $tenant->address,
                'jurisdiction' => $tenant->jurisdiction,
                'brand_color' => $tenant->brand_color,
                'theme' => $tenant->theme,
                'notifications_production_mode' => (bool) ($tenant->notifications_production_mode ?? false),
                'notifications_email_enabled' => (bool) ($tenant->notifications_email_enabled ?? false),
                'notifications_sms_enabled' => (bool) ($tenant->notifications_sms_enabled ?? false),
                'notifications_channel' => in_array((string) ($tenant->notifications_channel ?? 'email'), ['email', 'whatsapp', 'both', 'email_sms'], true)
                    ? (string) $tenant->notifications_channel
                    : 'email',
                'test_notification_emails' => is_array($tenant->test_notification_emails) ? array_values($tenant->test_notification_emails) : [],
                'test_notification_whatsapp_numbers' => is_array($tenant->test_notification_whatsapp_numbers) ? array_values($tenant->test_notification_whatsapp_numbers) : [],
                'test_notification_sms_numbers' => is_array($tenant->test_notification_sms_numbers) ? array_values($tenant->test_notification_sms_numbers) : [],
                'notifications_message_real' => $tenant->notifications_message_real,
                'notifications_message_simulacrum' => $tenant->notifications_message_simulacrum,
                'notifications_message_phase2' => $tenant->notifications_message_phase2,
                'notifications_include_credentials' => (bool) ($tenant->notifications_include_credentials ?? false),
                'two_factor_enabled' => (bool) ($tenant->two_factor_enabled ?? false),
                'director_activation_code_enabled' => (bool) ($tenant->director_activation_code_enabled ?? false),
                'director_activation_code_configured' => (bool) ($tenant->director_activation_code_hash ?? null),
                'logo_path' => $tenant->logo_path,
                'logo_url' => $logoUrl,
                'gps_min_lat' => $tenant->gps_min_lat,
                'gps_max_lat' => $tenant->gps_max_lat,
                'gps_min_lng' => $tenant->gps_min_lng,
                'gps_max_lng' => $tenant->gps_max_lng,
                'default_language' => $tenant->default_language,
                'timezone' => $tenant->timezone ?: 'Europe/Madrid',
                'conformacion_tiempo_limite' => $tenant->conformacion_tiempo_limite ?? 0,
                'languages' => $enabled,
            ],
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $validated = $request->validate([
            'logo' => ['required', 'file', 'image', 'max:4096'],
        ]);

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es', 'timezone' => 'Europe/Madrid'],
        );

        $file = $validated['logo'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $path = 'tenant_logos/'.$tenant->tenant_id.'/logo.'.$ext;

        Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

        $tenant->forceFill(['logo_path' => $path])->save();

        return response()->json([
            'message' => 'OK',
            'tenant' => [
                'tenant_id' => $tenant->tenant_id,
                'logo_path' => $tenant->logo_path,
                'logo_url' => $this->tenantLogoUrl($tenant),
            ],
        ]);
    }

    public function publicLogo(string $tenantId): BinaryFileResponse
    {
        $tenant = Tenant::query()->where('tenant_id', $tenantId)->first();
        if ($tenant === null || $tenant->logo_path === null || $tenant->logo_path === '') {
            abort(404);
        }

        $path = $tenant->logo_path;
        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        $fullPath = Storage::disk('public')->path($path);
        $mime = File::mimeType($fullPath) ?: 'application/octet-stream';

        return response()->file($fullPath, [
            'Content-Type' => $mime,
        ]);
    }

    public function publicProfile(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId === null || $tenantId === '') {
            $tenantId = trim((string) $request->query('tenant_id', ''));
        }

        if ($tenantId === '') {
            return response()->json(['message' => __('messages.tenant.missing')], 422);
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['name' => $tenantId, 'default_language' => 'es', 'timezone' => 'Europe/Madrid'],
        );

        return response()->json([
            'tenant' => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'logo_url' => $this->tenantLogoUrl($tenant),
            ],
        ]);
    }

    private function tenantLogoUrl(Tenant $tenant): ?string
    {
        $path = $tenant->logo_path;
        if ($path === null || $path === '') {
            return null;
        }

        $url = url('api/v1/tenant/'.$tenant->tenant_id.'/logo');
        if (! Storage::disk('public')->exists($path)) {
            return $url;
        }

        $v = Storage::disk('public')->lastModified($path);

        return $url.'?v='.$v;
    }

    private function ensureDefaultLanguageEnabled(Tenant $tenant): void
    {
        $hasAnyEnabled = TenantLanguage::query()
            ->where('tenant_id', $tenant->tenant_id)
            ->where('is_active', true)
            ->exists();

        if ($hasAnyEnabled) {
            return;
        }

        TenantLanguage::query()->updateOrCreate(
            ['tenant_id' => $tenant->tenant_id, 'language_code' => $tenant->default_language ?: 'es'],
            ['is_active' => true],
        );
    }
}
