<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Fetch last 5 IDs (assuming auto-increment ID? No, UUID).
// We can't sort easily. Just fetch 5.
$activations = DB::table('activacion_del_plan_trs')->limit(10)->get();

echo "Found " . count($activations) . " activations.\n";

foreach ($activations as $act) {
    echo "------------------------------------------------\n";
    echo "ID: " . $act->{'ac_de_pl-id'} . "\n";
    echo "  Nivel: " . ($act->{'ac_de_pl-ni_al_id-fk-inicial'} ?? 'N/A') . "\n";
    echo "  Fecha: " . ($act->{'ac_de_pl-fecha_activac'} ?? 'N/A') . " " . ($act->{'ac_de_pl-hora_activac'} ?? 'N/A') . "\n";
    
    $actions = DB::table('ejecucion_accion_trs')
        ->where('ej_ac-ac_de_pl_id-fk', $act->{'ac_de_pl-id'})
        ->count();
    echo "  Acciones (ejecucion_accion_trs): " . $actions . "\n";
}
