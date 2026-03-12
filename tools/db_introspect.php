<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$table = $argv[1] ?? 'accion_set_detalle_canal_cfg';
$db = $argv[2] ?? 'emta_db';
$cols = DB::select("SHOW COLUMNS FROM {$db}.{$table}");
echo json_encode($cols, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
