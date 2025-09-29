<?php

namespace CoreSuit\MigrationGen\Providers;

use Illuminate\Support\ServiceProvider;
use CoreSuit\MigrationGen\Console\MakeEntity;
use CoreSuit\MigrationGen\Console\MakeInit;

class CrudGenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeEntity::class,
                MakeInit::class, 
            ]);

            $this->publishes([
                __DIR__ . '/../stubs' => base_path('stubs/coresuit'),
            ], 'coresuit-stubs');
        }
    }
}
