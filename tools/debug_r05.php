<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "Querying for Risk: R05 using correct columns\n";
    
    // Correct column names based on previous output
    $col_id = 'ri_ni_ac_se-id';
    $col_tenant = 'ri_ni_ac_se-tenant_id';
    $col_riesgo = 'ri_ni_ac_se-rie_id-fk';
    $col_nivel = 'ri_ni_ac_se-ni_al_id-fk';
    $col_action_set = 'ri_ni_ac_se-ac_se_id-fk';
    $col_activo = 'ri_ni_ac_se-activo';

    $mappings = DB::table('riesgo_nivel_accion_set_cfg')
        ->where($col_tenant, 'Morell')
        ->where($col_riesgo, 'R05')
        ->get();

    if ($mappings->isEmpty()) {
        echo "No mappings found.\n";
    } else {
        foreach ($mappings as $m) {
            $activo = $m->$col_activo ?? 'N/A';
            echo "  - Nivel: {$m->$col_nivel} -> Action Set: {$m->$col_action_set} (Activo: $activo)\n";
        }
    }
    
    // Also check the action sets mentioned
    $actionSets = ['AS05', 'AS13', 'AS22'];
    echo "\nChecking Actions count for Action Sets:\n";

    // Re-check schema for details
    $columns = DB::select('DESCRIBE accion_set_detalle_cfg');
    $col_as_id = '';
    $col_activo_det = '';
    
    foreach ($columns as $col) {
        if (strpos($col->Field, 'ac_se_id-fk') !== false) $col_as_id = $col->Field;
        if (strpos($col->Field, 'activo') !== false) $col_activo_det = $col->Field;
    }

    foreach ($actionSets as $asId) {
        $query = DB::table('accion_set_detalle_cfg')->where($col_as_id, $asId);
        if ($col_activo_det) {
            $query->whereRaw("UPPER(COALESCE(`$col_activo_det`, 'SI')) <> 'NO'");
        }
        
        $count = $query->count();
        echo "  Action Set: $asId -> $count actions found.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
