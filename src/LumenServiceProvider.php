<?php

namespace Huangdijia\Trigger;

use Illuminate\Support\ServiceProvider;

class LumenServiceProvider extends ServiceProvider
{
    public function boot()
    {
    }

    public function register()
    {
        $this->configure();
        $this->registerCommands();
    }

    public function configure()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/trigger.php', 'trigger');
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/trigger.php' => $this->app->basePath('config/trigger.php')]);
        }
    }

    public function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\StartCommand::class,
            ]);
        }
    }
}