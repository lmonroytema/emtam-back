<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'director_activation_code_hash')) {
                $table->string('director_activation_code_hash')->nullable();
            }
            if (! Schema::hasColumn('tenants', 'director_activation_code_enabled')) {
                $table->boolean('director_activation_code_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('tenants', 'director_activation_code_hash')) {
                $cols[] = 'director_activation_code_hash';
            }
            if (Schema::hasColumn('tenants', 'director_activation_code_enabled')) {
                $cols[] = 'director_activation_code_enabled';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
