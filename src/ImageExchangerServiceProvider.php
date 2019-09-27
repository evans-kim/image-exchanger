<?php

namespace EvansKim\ImageExchanger;

use Illuminate\Support\ServiceProvider;

class ImageExchangerServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/image-exchanger.php' => config_path('image-exchanger.php'),
        ]);
        if ($this->app->runningInConsole()) {
            $this->commands([
                ImageExchangerCommand::class
            ]);
        }
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/image-exchanger.php', 'image-exchanger'
        );
    }
}
