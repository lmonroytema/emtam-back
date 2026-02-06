<?php

use App\Http\Controllers\Api\V1\TableCrudController;
use App\Services\TableSchemaService;
use Illuminate\Support\Facades\Route;

try {
    $schema = app(TableSchemaService::class);
    $tables = $schema->allowedTables();
} catch (Throwable $e) {
    $tables = [];
}

Route::prefix('db')->group(function () use ($tables) {
    foreach ($tables as $table) {
        Route::get($table, [TableCrudController::class, 'index'])->defaults('table', $table);
        Route::post($table, [TableCrudController::class, 'store'])->defaults('table', $table);
        Route::get($table.'/one', [TableCrudController::class, 'show'])->defaults('table', $table);
        Route::put($table.'/one', [TableCrudController::class, 'update'])->defaults('table', $table);
        Route::delete($table.'/one', [TableCrudController::class, 'destroy'])->defaults('table', $table);
        Route::get($table.'/schema', [TableCrudController::class, 'schema'])->defaults('table', $table);
    }
});
