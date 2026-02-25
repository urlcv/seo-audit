<?php

declare(strict_types=1);

namespace URLCV\SeoAudit\Laravel;

use Illuminate\Support\ServiceProvider;

class SeoAuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'seo-audit');
    }
}
