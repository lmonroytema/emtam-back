<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "Checking Tipo Riesgo for R05:\n";
    $riesgoId = 'R05';
    
    // Check riesgo_cat schema
    $cols = DB::select('DESCRIBE riesgo_cat');
    $col_tr = '';
    $col_tenant = '';
    foreach ($cols as $c) {
        // echo "  {$c->Field}\n";
        if (strpos($c->Field, 'ti_ri_id-fk') !== false) $col_tr = $c->Field;
        if (strpos($c->Field, 'tenant_id') !== false) $col_tenant = $c->Field;
    }
    
    echo "Columns: TR=$col_tr, Tenant=$col_tenant\n";

    $query = DB::table('riesgo_cat')->where('rie-id', $riesgoId);
    if ($col_tenant) {
        $query->where($col_tenant, 'Morell');
    }
    
    $riesgo = $query->first();
        
    if (!$riesgo) {
        echo "Risk not found.\n";
    } else {
        $tipoRiesgoId = $riesgo->$col_tr;
        echo "Tipo Riesgo ID: $tipoRiesgoId\n";
        
        if ($tipoRiesgoId) {
            echo "Checking mappings for Tipo Riesgo $tipoRiesgoId + NA05:\n";
            
            // Check tipo_riesgo_nivel_accion_set_cfg schema
            $cols = DB::select('DESCRIBE tipo_riesgo_nivel_accion_set_cfg');
            $col_tr_fk = '';
            $col_ni_fk = '';
            $col_as_fk = '';
            foreach ($cols as $c) {
                if (strpos($c->Field, 'ti_ri_id-fk') !== false) $col_tr_fk = $c->Field;
                if (strpos($c->Field, 'ni_al_id-fk') !== false) $col_ni_fk = $c->Field;
                if (strpos($c->Field, 'ac_se_id-fk') !== false) $col_as_fk = $c->Field;
            }
            
            echo "Columns: TR_FK=$col_tr_fk, NI_FK=$col_ni_fk, AS_FK=$col_as_fk\n";

            $mappings = DB::table('tipo_riesgo_nivel_accion_set_cfg')
                ->where($col_tr_fk, $tipoRiesgoId)
                ->where($col_ni_fk, 'NA05')
                ->get();
                
            if ($mappings->isEmpty()) {
                echo "No mappings found for Tipo Riesgo.\n";
            } else {
                foreach ($mappings as $m) {
                    echo "  Found: {$m->$col_ni_fk} -> {$m->$col_as_fk}\n";
                }
            }
        }
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
