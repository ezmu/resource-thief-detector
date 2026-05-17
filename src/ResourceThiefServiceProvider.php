<?php

namespace ResourceThief;

use Illuminate\Support\ServiceProvider;
use ResourceThief\Console\TraceCommand;

class ResourceThiefServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TraceCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__ . '/../config/resource-thief.php' => config_path('resource-thief.php'),
        ], 'resource-thief-config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/resource-thief.php',
            'resource-thief'
        );
    }
}
