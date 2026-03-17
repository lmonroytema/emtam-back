<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'notifications_email_enabled')) {
                $table->boolean('notifications_email_enabled')->default(false)->after('notifications_production_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'notifications_email_enabled')) {
                $table->dropColumn('notifications_email_enabled');
            }
        });
    }
};
