<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('riesgo_cat')) {
            return;
        }

        if (! Schema::hasTable('ev_lugar_riesgo_trs')) {
            return;
        }

        $mapping = [
            'RIEV_INUNDACIONS_7c9331' => 'R05',
            'RIEV_VENTADES_9d7d32' => 'R08',
            'RIEV_PANDEMIES_76e7f4' => 'R09',
            'RIEV_EMERGENCIES_PER_ONADES_DE_CALOR_04c661' => 'R01',
            'RIEV_EMERGENCIES_PER_ONADES_DE_FRED_fb3b4b' => 'R02',
            'RIEV_EMERGENCIES_PER_CONCENTRACIO_DE_PERSONES_d2b691' => 'R03',
            'RIEV_ACCIDENT_EN_EL_TRANSPORT_DE_MERCADERIES_PERILLOSES_9c610f' => 'R06',
            'RIEV_PLASEQTA_980e2a' => 'R07',
        ];

        foreach ($mapping as $oldId => $newId) {
            $old = DB::table('riesgo_cat')->where('rie-id', $oldId)->first();
            $new = DB::table('riesgo_cat')->where('rie-id', $newId)->first();

            if ($new === null && $old !== null) {
                DB::table('riesgo_cat')->insert([
                    'rie-id' => $newId,
                    'rie-tenant_id' => $old->{'rie-tenant_id'} ?? null,
                    'rie-cod' => $newId,
                    'rie-nombre' => $old->{'rie-nombre'} ?? null,
                    'rie-ti_ri_id-fk' => $old->{'rie-ti_ri_id-fk'} ?? null,
                    'rie-descrip' => $old->{'rie-descrip'} ?? null,
                    'rie-evaluac' => $old->{'rie-evaluac'} ?? null,
                    'rie-plan_espec' => $old->{'rie-plan_espec'} ?? null,
                    'rie-activo' => $old->{'rie-activo'} ?? 'SI',
                    'rie-orden' => $old->{'rie-orden'} ?? null,
                    'rie-padre_id-fk' => null,
                    'rie-nivel' => '0',
                ]);
                $new = DB::table('riesgo_cat')->where('rie-id', $newId)->first();
            }

            if ($new !== null) {
                DB::table('riesgo_cat')->where('rie-id', $newId)->update([
                    'rie-cod' => $new->{'rie-cod'} ?? $newId,
                    'rie-nivel' => $new->{'rie-nivel'} ?? '0',
                ]);
            }

            if (Schema::hasColumn('riesgo_cat', 'rie-padre_id-fk')) {
                DB::table('riesgo_cat')->where('rie-padre_id-fk', $oldId)->update([
                    'rie-padre_id-fk' => $newId,
                ]);
            }

            DB::table('ev_lugar_riesgo_trs')->where('ev_lu_rie-rie_id-fk', $oldId)->update([
                'ev_lu_rie-rie_id-fk' => $newId,
            ]);

            $hasChildren = 0;
            if (Schema::hasColumn('riesgo_cat', 'rie-padre_id-fk')) {
                $hasChildren = (int) DB::table('riesgo_cat')->where('rie-padre_id-fk', $oldId)->count();
            }
            $hasRelations = (int) DB::table('ev_lugar_riesgo_trs')->where('ev_lu_rie-rie_id-fk', $oldId)->count();

            if ($hasChildren === 0 && $hasRelations === 0) {
                DB::table('riesgo_cat')->where('rie-id', $oldId)->delete();
            }
        }

        DB::table('ev_lugar_riesgo_trs')->where('ev_lu_rie-rie_id-fk', 'RIEV_FILTRO_199a81')->delete();
        $hasFiltroChildren = 0;
        if (Schema::hasColumn('riesgo_cat', 'rie-padre_id-fk')) {
            $hasFiltroChildren = (int) DB::table('riesgo_cat')->where('rie-padre_id-fk', 'RIEV_FILTRO_199a81')->count();
        }
        if ($hasFiltroChildren === 0) {
            DB::table('riesgo_cat')->where('rie-id', 'RIEV_FILTRO_199a81')->delete();
        }
    }

    public function down(): void {}
};
