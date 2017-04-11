<?php

namespace BohSchu\Exact;

use Illuminate\Support\ServiceProvider;

class ExactServiceProvider extends ServiceProvider {

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/config/exact.php' => config_path('exact.php')
        ]);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
    }

}
