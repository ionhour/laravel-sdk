<?php

declare(strict_types=1);

namespace Ionhour\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Throwable;

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
                apiBaseUrl: rtrim((string) ($config['api_base_url'] ?? 'https://api.ionhour.com/api'), '/'),
                apiKey: is_string($config['api_key'] ?? null) ? $config['api_key'] : null,
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
        $this->registerSlugSchedulerMacro();
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

    /**
     * Add `->pingIonhourBySlug($slug, ['create' => true])`, the slug-mode twin
     * of `pingIonhour`. The job is registered when the hook fires. If slug
     * mode is unusable then (no API key, invalid slug or options), the ping is
     * skipped with a warning so the scheduler is never interrupted. Usage-error
     * messages never contain the API key.
     */
    private function registerSlugSchedulerMacro(): void
    {
        if (! class_exists(Event::class) || Event::hasMacro('pingIonhourBySlug')) {
            return;
        }

        $ping = static function (string $slug, array $options, string $kind): void {
            try {
                $job = app(IonhourManager::class)->jobBySlug($slug, $options);
            } catch (InvalidArgumentException $e) {
                try {
                    Log::warning('Ionhour scheduler ping skipped: '.$e->getMessage(), ['slug' => $slug]);
                } catch (Throwable) {
                    // Logging must never break the scheduler.
                }

                return;
            }
            $kind === 'fail' ? $job->fail() : $job->success();
        };

        Event::macro('pingIonhourBySlug', function (string $slug, array $options = []) use ($ping) {
            /** @var Event $this */
            $this->onSuccess(fn () => $ping($slug, $options, 'success'));
            $this->onFailure(fn () => $ping($slug, $options, 'fail'));

            return $this;
        });
    }
}
