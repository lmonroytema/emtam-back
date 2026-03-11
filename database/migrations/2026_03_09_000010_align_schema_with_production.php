<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accion_set_detalle_cfg')) {
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-detalle') && ! Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-obligatoria')) {
                Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                    $table->text('ac_se_de-obligatoria')->nullable();
                });
                DB::table('accion_set_detalle_cfg')->update(['ac_se_de-obligatoria' => null]);
                Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                    $table->dropColumn('ac_se_de-detalle');
                });
            } elseif (! Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-obligatoria')) {
                Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                    $table->text('ac_se_de-obligatoria')->nullable();
                });
            }
        }

        if (Schema::hasTable('ev_lugar_mst') && Schema::hasColumn('ev_lugar_mst', 'ev_lu_coo-longitud')) {
            DB::statement("ALTER TABLE `ev_lugar_mst` MODIFY `ev_lu_coo-longitud` DECIMAL(13,10) NULL");
        }

        if (Schema::hasTable('password_reset_tokens') && ! Schema::hasColumn('password_reset_tokens', 'tenant_id')) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->string('tenant_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::statement("ALTER TABLE `personal_access_tokens` MODIFY `name` TEXT NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accion_set_detalle_cfg')) {
            if (Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-obligatoria') && ! Schema::hasColumn('accion_set_detalle_cfg', 'ac_se_de-detalle')) {
                Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                    $table->text('ac_se_de-detalle')->nullable();
                });
                Schema::table('accion_set_detalle_cfg', function (Blueprint $table) {
                    $table->dropColumn('ac_se_de-obligatoria');
                });
            }
        }

        if (Schema::hasTable('ev_lugar_mst') && Schema::hasColumn('ev_lugar_mst', 'ev_lu_coo-longitud')) {
            DB::statement("ALTER TABLE `ev_lugar_mst` MODIFY `ev_lu_coo-longitud` DECIMAL(10,7) NULL");
        }

        if (Schema::hasTable('password_reset_tokens') && Schema::hasColumn('password_reset_tokens', 'tenant_id')) {
            Schema::table('password_reset_tokens', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::statement("ALTER TABLE `personal_access_tokens` MODIFY `name` VARCHAR(255) NOT NULL");
        }
    }
};
