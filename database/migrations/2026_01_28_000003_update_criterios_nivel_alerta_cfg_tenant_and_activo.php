<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('criterios_nivel_alerta_cfg')) {
            return;
        }

        if (! Schema::hasColumn('criterios_nivel_alerta_cfg', 'id_tenant')) {
            Schema::table('criterios_nivel_alerta_cfg', function (Blueprint $table) {
                $table->string('id_tenant', 255)->nullable()->after('id');
                $table->index(['id_tenant']);
            });
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            if (Schema::hasTable('criterios_nivel_alerta_cfg__tmp')) {
                Schema::drop('criterios_nivel_alerta_cfg__tmp');
            }

            Schema::create('criterios_nivel_alerta_cfg__tmp', function (Blueprint $table) {
                $table->increments('id');
                $table->string('id_tenant', 255)->nullable();
                $table->string('rie_cod', 255);
                $table->text('descripcion_criterios');
                $table->string('ni_al_nombre', 255);
                $table->integer('criterio_orden')->default(0);
                $table->string('activo', 2)->default('SI');
                $table->index(['rie_cod', 'activo']);
                $table->index(['id_tenant']);
            });

            DB::statement(
                "INSERT INTO criterios_nivel_alerta_cfg__tmp (id, id_tenant, rie_cod, descripcion_criterios, ni_al_nombre, criterio_orden, activo)
                 SELECT
                    id,
                    COALESCE(NULLIF(TRIM(id_tenant), ''), 'Morell') AS id_tenant,
                    rie_cod,
                    descripcion_criterios,
                    ni_al_nombre,
                    COALESCE(criterio_orden, 0) AS criterio_orden,
                    CASE
                        WHEN activo IS NULL THEN 'SI'
                        WHEN CAST(activo AS TEXT) = '1' THEN 'SI'
                        WHEN CAST(activo AS TEXT) = '0' THEN 'NO'
                        WHEN UPPER(TRIM(CAST(activo AS TEXT))) IN ('SI', 'NO') THEN UPPER(TRIM(CAST(activo AS TEXT)))
                        ELSE 'SI'
                    END AS activo
                 FROM criterios_nivel_alerta_cfg"
            );

            Schema::drop('criterios_nivel_alerta_cfg');
            Schema::rename('criterios_nivel_alerta_cfg__tmp', 'criterios_nivel_alerta_cfg');
        } else {
            DB::table('criterios_nivel_alerta_cfg')
                ->whereNull('id_tenant')
                ->orWhere('id_tenant', '=', '')
                ->update(['id_tenant' => 'Morell']);

            try {
                if ($driver === 'mysql') {
                    DB::statement("ALTER TABLE `criterios_nivel_alerta_cfg` MODIFY `activo` VARCHAR(2) NULL DEFAULT 'SI'");
                } elseif ($driver === 'pgsql') {
                    DB::statement(
                        "ALTER TABLE \"criterios_nivel_alerta_cfg\"
                         ALTER COLUMN \"activo\" TYPE VARCHAR(2)
                         USING (CASE
                            WHEN \"activo\" IS NULL THEN 'SI'
                            WHEN TRIM(CAST(\"activo\" AS TEXT)) = '1' THEN 'SI'
                            WHEN TRIM(CAST(\"activo\" AS TEXT)) = '0' THEN 'NO'
                            WHEN UPPER(TRIM(CAST(\"activo\" AS TEXT))) IN ('SI', 'NO') THEN UPPER(TRIM(CAST(\"activo\" AS TEXT)))
                            ELSE 'SI'
                         END)"
                    );
                    DB::statement("ALTER TABLE \"criterios_nivel_alerta_cfg\" ALTER COLUMN \"activo\" SET DEFAULT 'SI'");
                } elseif ($driver === 'sqlsrv') {
                    DB::statement('ALTER TABLE criterios_nivel_alerta_cfg ALTER COLUMN activo VARCHAR(2) NULL');
                }
            } catch (\Throwable) {
            }

            try {
                $rows = DB::table('criterios_nivel_alerta_cfg')->select(['id', 'activo'])->get();
                foreach ($rows as $r) {
                    $id = (int) ($r->id ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $raw = $r->activo;
                    $val = strtoupper(trim((string) ($raw ?? '')));
                    $normalized = match ($val) {
                        '', '1', 'SI' => 'SI',
                        '0', 'NO' => 'NO',
                        default => 'SI',
                    };
                    if ((string) ($raw ?? '') !== $normalized) {
                        DB::table('criterios_nivel_alerta_cfg')->where('id', $id)->update(['activo' => $normalized]);
                    }
                }
            } catch (\Throwable) {
                DB::table('criterios_nivel_alerta_cfg')->where('activo', '=', 1)->update(['activo' => 'SI']);
                DB::table('criterios_nivel_alerta_cfg')->where('activo', '=', 0)->update(['activo' => 'NO']);
                DB::table('criterios_nivel_alerta_cfg')->whereNull('activo')->update(['activo' => 'SI']);
            }
        }

        $tenantIds = DB::table('criterios_nivel_alerta_cfg')
            ->select('id_tenant')
            ->distinct()
            ->pluck('id_tenant')
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->values()
            ->all();

        foreach ($tenantIds as $tenantId) {
            $ids = DB::table('criterios_nivel_alerta_cfg')
                ->where('id_tenant', $tenantId)
                ->orderBy('criterio_orden')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $n = 0;
            foreach ($ids as $id) {
                $n++;
                DB::table('criterios_nivel_alerta_cfg')->where('id', $id)->update(['criterio_orden' => $n]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('criterios_nivel_alerta_cfg')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            if (Schema::hasTable('criterios_nivel_alerta_cfg__tmp_down')) {
                Schema::drop('criterios_nivel_alerta_cfg__tmp_down');
            }

            Schema::create('criterios_nivel_alerta_cfg__tmp_down', function (Blueprint $table) {
                $table->increments('id');
                $table->string('rie_cod', 255);
                $table->text('descripcion_criterios');
                $table->string('ni_al_nombre', 255);
                $table->integer('criterio_orden')->default(0);
                $table->integer('activo')->default(1);
                $table->index(['rie_cod', 'activo']);
            });

            DB::statement(
                "INSERT INTO criterios_nivel_alerta_cfg__tmp_down (id, rie_cod, descripcion_criterios, ni_al_nombre, criterio_orden, activo)
                 SELECT
                    id,
                    rie_cod,
                    descripcion_criterios,
                    ni_al_nombre,
                    COALESCE(criterio_orden, 0) AS criterio_orden,
                    CASE
                        WHEN UPPER(TRIM(COALESCE(activo, 'SI'))) = 'NO' THEN 0
                        ELSE 1
                    END AS activo
                 FROM criterios_nivel_alerta_cfg"
            );

            Schema::drop('criterios_nivel_alerta_cfg');
            Schema::rename('criterios_nivel_alerta_cfg__tmp_down', 'criterios_nivel_alerta_cfg');

            return;
        }

        if (Schema::hasColumn('criterios_nivel_alerta_cfg', 'id_tenant')) {
            Schema::table('criterios_nivel_alerta_cfg', function (Blueprint $table) {
                $table->dropColumn('id_tenant');
            });
        }

        try {
            if ($driver === 'mysql') {
                DB::statement(
                    'ALTER TABLE `criterios_nivel_alerta_cfg`
                     MODIFY `activo` INT NULL DEFAULT 1'
                );
                DB::statement(
                    "UPDATE `criterios_nivel_alerta_cfg`
                     SET `activo` = CASE WHEN UPPER(TRIM(COALESCE(CAST(`activo` AS CHAR(10)), 'SI'))) = 'NO' THEN 0 ELSE 1 END"
                );
            } elseif ($driver === 'pgsql') {
                DB::statement(
                    "ALTER TABLE \"criterios_nivel_alerta_cfg\"
                     ALTER COLUMN \"activo\" TYPE INTEGER
                     USING (CASE WHEN UPPER(TRIM(COALESCE(CAST(\"activo\" AS TEXT), 'SI'))) = 'NO' THEN 0 ELSE 1 END)"
                );
                DB::statement('ALTER TABLE "criterios_nivel_alerta_cfg" ALTER COLUMN "activo" SET DEFAULT 1');
            } elseif ($driver === 'sqlsrv') {
                DB::statement('ALTER TABLE criterios_nivel_alerta_cfg ALTER COLUMN activo INT NULL');
            }
        } catch (\Throwable) {
        }
    }
};
