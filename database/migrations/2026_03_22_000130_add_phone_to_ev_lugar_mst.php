<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ev_lugar_mst')) {
            return;
        }

        Schema::table('ev_lugar_mst', function (Blueprint $table) {
            if (! Schema::hasColumn('ev_lugar_mst', 'ev_lu-telefono')) {
                $table->string('ev_lu-telefono', 64)->nullable()->after('ev_lu-direccion');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ev_lugar_mst')) {
            return;
        }

        Schema::table('ev_lugar_mst', function (Blueprint $table) {
            if (Schema::hasColumn('ev_lugar_mst', 'ev_lu-telefono')) {
                $table->dropColumn('ev_lu-telefono');
            }
        });
    }
};
