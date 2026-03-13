<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Schema for persona_mst:\n";
$cols = DB::select("DESCRIBE persona_mst");
foreach ($cols as $c) {
    echo "  {$c->Field}\n";
}
