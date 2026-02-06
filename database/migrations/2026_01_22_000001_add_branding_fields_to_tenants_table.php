<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('brand_color', 32)->nullable()->after('default_language');
            $table->string('logo_path')->nullable()->after('brand_color');
            $table->string('address')->nullable()->after('name');
            $table->string('jurisdiction')->nullable()->after('address');
            $table->decimal('gps_min_lat', 10, 7)->nullable()->after('jurisdiction');
            $table->decimal('gps_max_lat', 10, 7)->nullable()->after('gps_min_lat');
            $table->decimal('gps_min_lng', 10, 7)->nullable()->after('gps_max_lat');
            $table->decimal('gps_max_lng', 10, 7)->nullable()->after('gps_min_lng');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'brand_color',
                'logo_path',
                'address',
                'jurisdiction',
                'gps_min_lat',
                'gps_max_lat',
                'gps_min_lng',
                'gps_max_lng',
            ]);
        });
    }
};
