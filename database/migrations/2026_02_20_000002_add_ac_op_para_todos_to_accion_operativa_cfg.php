<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accion_operativa_cfg')) {
            return;
        }

        if (! Schema::hasColumn('accion_operativa_cfg', 'ac_op-para_todos')) {
            Schema::table('accion_operativa_cfg', function (Blueprint $table) {
                $table->string('ac_op-para_todos', 2)->nullable()->default('SI');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('accion_operativa_cfg')) {
            return;
        }

        if (Schema::hasColumn('accion_operativa_cfg', 'ac_op-para_todos')) {
            Schema::table('accion_operativa_cfg', function (Blueprint $table) {
                $table->dropColumn('ac_op-para_todos');
            });
        }
    }
};
