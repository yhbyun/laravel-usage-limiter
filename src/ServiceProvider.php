<?php

namespace NabilHassen\LaravelUsageLimiter;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider as SupportServiceProvider;
use NabilHassen\LaravelUsageLimiter\Commands\CreateLimit;
use NabilHassen\LaravelUsageLimiter\Commands\DeleteLimit;
use NabilHassen\LaravelUsageLimiter\Commands\ListLimits;
use NabilHassen\LaravelUsageLimiter\Commands\ResetCache;
use NabilHassen\LaravelUsageLimiter\Commands\ResetLimitUsages;
use NabilHassen\LaravelUsageLimiter\Contracts\Limit;

class ServiceProvider extends SupportServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/limit.php', 'limit');

        $this->app->bind(Limit::class, $this->app['config']['limit.models.limit']);

        $this->app->singleton(LimitManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/limit.php' => config_path('limit.php'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ]);

        Blade::directive('limit', function (Model $model, /*string|Limit*/ $name, ?string $plan = null): bool {
            try {
                return $model->hasEnoughLimit($name, $plan);
            } catch (\Throwable $th) {
                return false;
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateLimit::class,
                DeleteLimit::class,
                ListLimits::class,
                // ResetLimitUsages::class,
                ResetCache::class,
            ]);
        }
    }
}
