<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directorio_grupo_cat')) {
            Schema::create('directorio_grupo_cat', function (Blueprint $table) {
                $table->string('dir_gr-id', 50)->primary();
                $table->string('dir_gr-tenant_id', 255);
                $table->string('dir_gr-cod', 50);
                $table->string('dir_gr-nombre', 255);
                $table->string('dir_gr-descrip', 500)->nullable();
                $table->string('dir_gr-activo', 2)->default('SI');
                $table->unsignedInteger('dir_gr-orden')->nullable();

                $table->index(['dir_gr-tenant_id']);
                $table->index(['dir_gr-cod']);
            });
        }

        if (! Schema::hasTable('directorio_contacto_mst')) {
            Schema::create('directorio_contacto_mst', function (Blueprint $table) {
                $table->string('dir_con-id', 50)->primary();
                $table->string('dir_con-tenant_id', 255);
                $table->string('dir_con-dir_gr_id-fk', 50)->nullable();
                $table->string('dir_con-cod', 50)->nullable();
                $table->string('dir_con-nombre', 255);
                $table->string('dir_con-telefono', 50)->nullable();
                $table->string('dir_con-telefono_2', 50)->nullable();
                $table->string('dir_con-email', 255)->nullable();
                $table->string('dir_con-frecuencia', 100)->nullable();
                $table->string('dir_con-responsable', 255)->nullable();
                $table->string('dir_con-direccion', 255)->nullable();
                $table->string('dir_con-situacion', 50)->nullable();
                $table->string('dir_con-coordenadas', 100)->nullable();
                $table->unsignedInteger('dir_con-capacidad')->nullable();
                $table->string('dir_con-notas', 500)->nullable();
                $table->string('dir_con-activo', 2)->default('SI');
                $table->unsignedInteger('dir_con-orden')->nullable();

                $table->index(['dir_con-tenant_id']);
                $table->index(['dir_con-dir_gr_id-fk']);
                $table->index(['dir_con-cod']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('directorio_contacto_mst');
        Schema::dropIfExists('directorio_grupo_cat');
    }
};
