<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = [
    'activacion_del_plan_trs',
    'activacion_nivel_hist_trs',
    'asignacion_en_funciones_trs',
    'cronologia_emergencia_trs'
];

foreach ($tables as $t) {
    echo "Schema for $t:\n";
    $cols = DB::select("DESCRIBE $t");
    foreach ($cols as $c) {
        echo "  {$c->Field} ({$c->Type})\n";
    }
    echo "\n";
}
