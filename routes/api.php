<?php

use App\Http\Controllers\Api\V1\ActivationController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TableCrudController;
use App\Http\Controllers\Api\V1\TenantLanguageController;
use App\Http\Controllers\Api\V1\TenantSettingsController;
use App\Http\Controllers\Api\V1\TenantUsersController;
use App\Http\Controllers\Api\V1\UserLanguageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::get('tenant/{tenantId}/logo', [TenantSettingsController::class, 'publicLogo']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('activacion', [ActivationController::class, 'store']);
        Route::get('activacion/preview', [ActivationController::class, 'preview']);
        Route::post('activacion/{activationId}/notificaciones/enviar', [ActivationController::class, 'sendNotifications']);
        Route::post('activacion/{activationId}/notificaciones/fin', [ActivationController::class, 'sendEndNotifications']);
        Route::get('acciones/mias', [ActivationController::class, 'myActions']);
        Route::post('acciones/confirmar', [ActivationController::class, 'confirmMyActions']);
        Route::post('activacion/{activationId}/documentos', [ActivationController::class, 'uploadDocuments']);
        Route::get('activacion/repositorio/riesgo/{riesgoId}', [ActivationController::class, 'listRiskRepository']);
        Route::get('activacion/repositorio/riesgo/{riesgoId}/archivo/{filename}', [ActivationController::class, 'downloadRiskRepositoryFile']);
        Route::post('activacion/repositorio/riesgo/{riesgoId}/enlaces', [ActivationController::class, 'storeRiskRepositoryLink']);
        Route::delete('activacion/repositorio/riesgo/{riesgoId}/enlaces/{filename}', [ActivationController::class, 'deleteRiskRepositoryLink']);

        Route::get('tables', [TableCrudController::class, 'tables']);
        Route::get('schema/{table}', [TableCrudController::class, 'schema']);

        Route::get('crud/{table}', [TableCrudController::class, 'index']);
        Route::post('crud/{table}', [TableCrudController::class, 'store']);
        Route::get('crud/{table}/one', [TableCrudController::class, 'show']);
        Route::put('crud/{table}/one', [TableCrudController::class, 'update']);
        Route::delete('crud/{table}/one', [TableCrudController::class, 'destroy']);

        require __DIR__.'/api_db.php';

        Route::get('tenant/settings', [TenantSettingsController::class, 'show']);
        Route::put('tenant/settings', [TenantSettingsController::class, 'update']);
        Route::post('tenant/logo', [TenantSettingsController::class, 'uploadLogo']);

        Route::get('tenant/users', [TenantUsersController::class, 'index']);
        Route::post('tenant/users', [TenantUsersController::class, 'store']);
        Route::put('tenant/users/{userId}', [TenantUsersController::class, 'update']);
        Route::delete('tenant/users/{userId}', [TenantUsersController::class, 'destroy']);

        Route::put('tenant/languages', [TenantLanguageController::class, 'update']);
        Route::put('user/language', [UserLanguageController::class, 'update']);
    });
});
