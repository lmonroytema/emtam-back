<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'test_notification_whatsapp_numbers')) {
                $after = Schema::hasColumn('tenants', 'test_notification_emails') ? 'test_notification_emails' : null;
                $col = $table->json('test_notification_whatsapp_numbers')->nullable();
                if ($after) {
                    $col->after($after);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'test_notification_whatsapp_numbers')) {
                $table->dropColumn('test_notification_whatsapp_numbers');
            }
        });
    }
};
