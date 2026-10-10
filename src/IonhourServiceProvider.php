<?php

declare(strict_types=1);

namespace Ionhour\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\ServiceProvider;

final class IonhourServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ionhour.php', 'ionhour');

        $this->app->singleton(IonhourManager::class, function ($app): IonhourManager {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('ionhour', []);

            return new IonhourManager(
                baseUrl: rtrim((string) ($config['base_url'] ?? 'https://signal.ionhour.com/api'), '/'),
                timeout: (int) ($config['timeout'] ?? 10),
                retries: (int) ($config['retries'] ?? 3),
            );
        });

        $this->app->alias(IonhourManager::class, 'ionhour');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ionhour.php' => $this->app->configPath('ionhour.php'),
            ], 'ionhour-config');
        }

        $this->registerSchedulerMacro();
    }

    /**
     * Add `->pingIonhour($token)` to scheduled events so a scheduled
     * command pings success on exit code 0 and fail otherwise.
     */
    private function registerSchedulerMacro(): void
    {
        if (! class_exists(Event::class) || Event::hasMacro('pingIonhour')) {
            return;
        }

        Event::macro('pingIonhour', function (string $token) {
            /** @var Event $this */
            $this->onSuccess(function () use ($token): void {
                app(IonhourManager::class)->registerJob($token)->success();
            });

            $this->onFailure(function () use ($token): void {
                app(IonhourManager::class)->registerJob($token)->fail();
            });

            return $this;
        });
    }
}
