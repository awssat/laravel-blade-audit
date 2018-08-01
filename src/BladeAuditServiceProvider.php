<?php

namespace Awssat\BladeAudit;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Awssat\BladeAudit\Commands\BladeAudit;

class BladeAuditServiceProvider extends ServiceProvider
{

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BladeAudit::class
            ]);
        }
    }

    // public function register()
    // {
    // }
}
