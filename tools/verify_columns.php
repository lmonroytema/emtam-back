<?php

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->boot();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$db = env('DB_DATABASE', 'emta_db');

$tables = DB::table('information_schema.tables')
    ->select('table_name')
    ->where('table_schema', $db)
    ->pluck('table_name')
    ->all();

$total = count($tables);
$uppercaseIssues = [];

foreach ($tables as $table) {
    $cols = Schema::getColumnListing($table);
    foreach ($cols as $c) {
        if (preg_match('/[A-Z]/', $c)) {
            $uppercaseIssues[$table][] = $c;
        }
    }
}

echo 'Tables: '.$total.PHP_EOL;
echo 'Tables with uppercase columns: '.count($uppercaseIssues).PHP_EOL;

foreach ($uppercaseIssues as $t => $cols) {
    echo $t.': '.implode(',', $cols).PHP_EOL;
}
