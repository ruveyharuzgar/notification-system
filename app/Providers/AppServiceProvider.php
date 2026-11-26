<?php
namespace App\Providers;

use App\Services\NotificationProvider;
use App\Services\WebhookProvider;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationProvider::class, WebhookProvider::class);
    }
}
