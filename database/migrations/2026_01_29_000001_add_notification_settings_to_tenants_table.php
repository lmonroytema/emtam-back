<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $afterGps = Schema::hasColumn('tenants', 'gps_max_lng');

        Schema::table('tenants', function (Blueprint $table) use ($afterGps) {
            if (! Schema::hasColumn('tenants', 'notifications_production_mode')) {
                $col = $table->boolean('notifications_production_mode')->default(false);
                if ($afterGps) {
                    $col->after('gps_max_lng');
                }
            }

            if (! Schema::hasColumn('tenants', 'test_notification_emails')) {
                $col = $table->json('test_notification_emails')->nullable();
                if ($afterGps) {
                    $col->after('notifications_production_mode');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('tenants', 'test_notification_emails')) {
                $cols[] = 'test_notification_emails';
            }
            if (Schema::hasColumn('tenants', 'notifications_production_mode')) {
                $cols[] = 'notifications_production_mode';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
