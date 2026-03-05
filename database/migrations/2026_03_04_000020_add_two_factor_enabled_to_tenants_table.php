<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'two_factor_enabled')) {
                $table->boolean('two_factor_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'two_factor_enabled')) {
                $table->dropColumn('two_factor_enabled');
            }
        });
    }
};
