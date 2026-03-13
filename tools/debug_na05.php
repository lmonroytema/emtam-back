<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $col_id = 'ri_ni_ac_se-id';
    $col_tenant = 'ri_ni_ac_se-tenant_id';
    $col_riesgo = 'ri_ni_ac_se-rie_id-fk';
    $col_nivel = 'ri_ni_ac_se-ni_al_id-fk';
    $col_action_set = 'ri_ni_ac_se-ac_se_id-fk';
    $col_activo = 'ri_ni_ac_se-activo';

    echo "Checking mapping for NA05:\n";
    $mappings = DB::table('riesgo_nivel_accion_set_cfg')
        ->where($col_tenant, 'Morell')
        ->where($col_riesgo, 'R05')
        ->where($col_nivel, 'NA05')
        ->get();

    if ($mappings->isEmpty()) {
        echo "No mappings found for R05 + NA05.\n";
    } else {
        foreach ($mappings as $m) {
            echo "  Found: {$m->$col_nivel} -> {$m->$col_action_set}\n";
        }
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
