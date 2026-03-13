<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tenant = DB::table('tenants')->first();
if ($tenant) {
    // Guess column names or use var_dump
    print_r($tenant);
} else {
    echo "No tenants found.\n";
}
