<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "Checking Alert Levels and Emergency Levels:\n";

    $levels = DB::table('nivel_alerta_cat')->get();
    foreach ($levels as $l) {
        $id = $l->{'ni_al-id'};
        $name = $l->{'ni_al-nombre'};
        $niEmId = $l->{'ni_al-ni_em_id-fk'};
        
        echo "Level: $id ($name) -> Emergency Level ID: $niEmId\n";
        
        if ($niEmId) {
            $emLevel = DB::table('nivel_emergencia_cat')->where('ni_em-id', $niEmId)->first();
            if ($emLevel) {
                $activa = $emLevel->{'ni_em-activa_plan'};
                echo "    Emergency Level: {$emLevel->{'ni_em-nombre'}} (Activa Plan: $activa)\n";
            } else {
                echo "    Emergency Level not found.\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
