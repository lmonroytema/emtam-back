<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grupos_directorio_cfg')) {
            Schema::create('grupos_directorio_cfg', function (Blueprint $table) {
                $table->string('gr_di-id', 50)->primary();
                $table->string('gr_di-tenant_id', 255);
                $table->string('gr_di-cod', 50);
                $table->string('gr_di-nombre', 255);
                $table->string('gr_di-descrip', 500)->nullable();
                $table->string('gr_di-activo', 2)->default('SI');
                $table->unsignedInteger('gr_di-orden')->nullable();

                $table->index(['gr_di-tenant_id']);
                $table->index(['gr_di-cod']);
            });
        }

        if (! Schema::hasTable('dato_grupo_directorio_cfg')) {
            Schema::create('dato_grupo_directorio_cfg', function (Blueprint $table) {
                $table->string('da_gr_di-id', 50)->primary();
                $table->string('da_gr_di-tenant_id', 255);
                $table->string('da_gr_di-gr_di_id-fk', 50);
                $table->string('da_gr_di-cod', 50)->nullable();
                $table->string('da_gr_di-cabecera', 255);
                $table->string('da_gr_di-tipo_dato', 30)->default('TEXTO');
                $table->unsignedInteger('da_gr_di-orden')->nullable();
                $table->string('da_gr_di-activo', 2)->default('SI');

                $table->index(['da_gr_di-tenant_id']);
                $table->index(['da_gr_di-gr_di_id-fk']);
            });
        }

        if (! Schema::hasTable('dato_directorio_cat')) {
            Schema::create('dato_directorio_cat', function (Blueprint $table) {
                $table->string('da_di-id', 60)->primary();
                $table->string('da_di-tenant_id', 255);
                $table->string('da_di-gr_di_id-fk', 50);
                $table->string('da_di-da_gr_di_id-fk', 50);
                $table->string('da_di-item_id', 60);
                $table->string('da_di-valor', 1000)->nullable();
                $table->unsignedInteger('da_di-orden')->nullable();
                $table->string('da_di-activo', 2)->default('SI');

                $table->index(['da_di-tenant_id']);
                $table->index(['da_di-gr_di_id-fk']);
                $table->index(['da_di-da_gr_di_id-fk']);
                $table->index(['da_di-item_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dato_directorio_cat');
        Schema::dropIfExists('dato_grupo_directorio_cfg');
        Schema::dropIfExists('grupos_directorio_cfg');
    }
};
