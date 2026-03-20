<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('persona_rol_grupo_cfg') || ! Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tipo_asignacion')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::getDatabaseName();
        $checks = DB::select(
            "SELECT constraint_name
             FROM information_schema.table_constraints
             WHERE table_schema = ?
               AND table_name = 'persona_rol_grupo_cfg'
               AND constraint_type = 'CHECK'
               AND constraint_name LIKE 'chk_pe_ro_gr_tipo_asignacion%'",
            [$database]
        );

        foreach ($checks as $check) {
            $name = (string) ($check->constraint_name ?? '');
            if ($name === '') {
                continue;
            }
            DB::statement("ALTER TABLE `persona_rol_grupo_cfg` DROP CHECK `{$name}`");
        }

        DB::statement(
            "ALTER TABLE `persona_rol_grupo_cfg`
             ADD CONSTRAINT `chk_pe_ro_gr_tipo_asignacion`
             CHECK (UPPER(COALESCE(`pe_ro_gr-tipo_asignacion`, '')) IN ('', 'TITULAR', 'SUPLENTE', 'LIDER'))"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('persona_rol_grupo_cfg') || ! Schema::hasColumn('persona_rol_grupo_cfg', 'pe_ro_gr-tipo_asignacion')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::getDatabaseName();
        $checks = DB::select(
            "SELECT constraint_name
             FROM information_schema.table_constraints
             WHERE table_schema = ?
               AND table_name = 'persona_rol_grupo_cfg'
               AND constraint_type = 'CHECK'
               AND constraint_name LIKE 'chk_pe_ro_gr_tipo_asignacion%'",
            [$database]
        );

        foreach ($checks as $check) {
            $name = (string) ($check->constraint_name ?? '');
            if ($name === '') {
                continue;
            }
            DB::statement("ALTER TABLE `persona_rol_grupo_cfg` DROP CHECK `{$name}`");
        }

        DB::statement(
            "ALTER TABLE `persona_rol_grupo_cfg`
             ADD CONSTRAINT `chk_pe_ro_gr_tipo_asignacion`
             CHECK (UPPER(COALESCE(`pe_ro_gr-tipo_asignacion`, '')) IN ('', 'TITULAR', 'SUPLENTE'))"
        );
    }
};
