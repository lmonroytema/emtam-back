<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenants', 'notifications_sms_enabled')) {
                $after = Schema::hasColumn('tenants', 'notifications_email_enabled')
                    ? 'notifications_email_enabled'
                    : (Schema::hasColumn('tenants', 'notifications_production_mode') ? 'notifications_production_mode' : null);
                $col = $table->boolean('notifications_sms_enabled')->default(false);
                if ($after !== null) {
                    $col->after($after);
                }
            }

            if (! Schema::hasColumn('tenants', 'test_notification_sms_numbers')) {
                $after = Schema::hasColumn('tenants', 'test_notification_whatsapp_numbers')
                    ? 'test_notification_whatsapp_numbers'
                    : (Schema::hasColumn('tenants', 'test_notification_emails') ? 'test_notification_emails' : null);
                $col = $table->json('test_notification_sms_numbers')->nullable();
                if ($after !== null) {
                    $col->after($after);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (Schema::hasColumn('tenants', 'test_notification_sms_numbers')) {
                $table->dropColumn('test_notification_sms_numbers');
            }
            if (Schema::hasColumn('tenants', 'notifications_sms_enabled')) {
                $table->dropColumn('notifications_sms_enabled');
            }
        });
    }
};

