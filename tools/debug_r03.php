<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $riesgoId = 'R03';
    echo "Checking mapping for Risk: $riesgoId\n";
    
    // Check riesgo_nivel_accion_set_cfg
    $mappings = DB::table('riesgo_nivel_accion_set_cfg')
        ->where('ri_ni_ac_se-rie_id-fk', $riesgoId)
        ->get();

    if ($mappings->isEmpty()) {
        echo "No mappings found for $riesgoId in riesgo_nivel_accion_set_cfg.\n";
    } else {
        foreach ($mappings as $m) {
            echo "  - Nivel: {$m->{'ri_ni_ac_se-ni_al_id-fk'}} -> Action Set: {$m->{'ri_ni_ac_se-ac_se_id-fk'}}\n";
        }
    }
    
    // Check Tipo Riesgo
    $riesgo = DB::table('riesgo_cat')->where('rie-id', $riesgoId)->first();
    if ($riesgo) {
        $tipoRiesgoId = $riesgo->{'rie-ti_ri_id-fk'};
        echo "Tipo Riesgo: $tipoRiesgoId\n";
        
        if ($tipoRiesgoId) {
            $tm = DB::table('tipo_riesgo_nivel_accion_set_cfg')
                ->where('ti_ri_ni_ac_se-ti_ri_id-fk', $tipoRiesgoId)
                ->get();
            foreach ($tm as $m) {
                echo "  - [Tipo] Nivel: {$m->{'ti_ri_ni_ac_se-ni_al_id-fk'}} -> Action Set: {$m->{'ti_ri_ni_ac_se-ac_se_id-fk'}}\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
