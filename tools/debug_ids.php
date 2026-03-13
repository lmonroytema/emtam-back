<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// Check one risk
$risk = DB::table('riesgo_cat')->first();
echo "Risk ID: " . $risk->{'rie-id'} . "\n";
echo "Risk Code: " . $risk->{'rie-cod'} . "\n";

// Check mapping table
$mapping = DB::table('riesgo_nivel_accion_set_cfg')->first();
if ($mapping) {
    echo "Mapping Risk FK: " . $mapping->{'ri_ni_ac_se-rie_id-fk'} . "\n";
    echo "Mapping Alert FK: " . $mapping->{'ri_ni_ac_se-ni_al_id-fk'} . "\n";
} else {
    echo "Mapping table empty.\n";
}
