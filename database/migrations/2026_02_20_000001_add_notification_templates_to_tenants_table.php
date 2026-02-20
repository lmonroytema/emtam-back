<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'notifications_message_real')) {
                $table->text('notifications_message_real')->nullable();
            }
            if (! Schema::hasColumn('tenants', 'notifications_message_simulacrum')) {
                $table->text('notifications_message_simulacrum')->nullable();
            }
            if (! Schema::hasColumn('tenants', 'notifications_include_credentials')) {
                $table->boolean('notifications_include_credentials')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('tenants', 'notifications_message_real')) {
                $cols[] = 'notifications_message_real';
            }
            if (Schema::hasColumn('tenants', 'notifications_message_simulacrum')) {
                $cols[] = 'notifications_message_simulacrum';
            }
            if (Schema::hasColumn('tenants', 'notifications_include_credentials')) {
                $cols[] = 'notifications_include_credentials';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
