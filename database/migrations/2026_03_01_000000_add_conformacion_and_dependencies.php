<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (!Schema::hasColumn('tenants', 'conformacion_tiempo_limite')) {
                    $table->integer('conformacion_tiempo_limite')->nullable()->default(0);
                }
            });
        }

        if (Schema::hasTable('accion_set_detalle_cfg')) {
            Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                if (!Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-dependencia_id-fk')) {
                    $table->string('ac_se_de-dependencia_id-fk', 191)->nullable();
                    $table->index('ac_se_de-dependencia_id-fk');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('tenants')) {
            Schema::table('tenants', function (Blueprint $table) {
                if (Schema::hasColumn('tenants', 'conformacion_tiempo_limite')) {
                    $table->dropColumn('conformacion_tiempo_limite');
                }
            });
        }

        if (Schema::hasTable('accion_set_detalle_cfg')) {
            Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-dependencia_id-fk')) {
                    $table->dropColumn('ac_se_de-dependencia_id-fk');
                }
            });
        }
    }
};
