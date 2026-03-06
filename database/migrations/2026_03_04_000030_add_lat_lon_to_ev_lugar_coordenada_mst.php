<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ev_lugar_coordenada_mst')) {
            return;
        }

        Schema::table('ev_lugar_coordenada_mst', function (Blueprint $table) {
            if (! Schema::hasColumn('ev_lugar_coordenada_mst', 'ev_lu_coo-longitud')) {
                $table->decimal('ev_lu_coo-longitud', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('ev_lugar_coordenada_mst', 'ev_lu_coo-latitud')) {
                $table->decimal('ev_lu_coo-latitud', 10, 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ev_lugar_coordenada_mst')) {
            return;
        }

        Schema::table('ev_lugar_coordenada_mst', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('ev_lugar_coordenada_mst', 'ev_lu_coo-longitud')) {
                $cols[] = 'ev_lu_coo-longitud';
            }
            if (Schema::hasColumn('ev_lugar_coordenada_mst', 'ev_lu_coo-latitud')) {
                $cols[] = 'ev_lu_coo-latitud';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
