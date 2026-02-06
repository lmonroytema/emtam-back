<?php

namespace App\Providers;

use App\Services\LanguageService;
use App\Services\TableSchemaService;
use App\Services\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(LanguageService::class);
        $this->app->singleton(TableSchemaService::class);
    }

    public function boot(): void {}
}
