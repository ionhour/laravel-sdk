<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Illuminate\Support\ServiceProvider;
use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\IonhourManager;
use Ionhour\Laravel\IonhourServiceProvider;
use Ionhour\Laravel\Job;
use InvalidArgumentException;

final class ManagerTest extends TestCase
{
    public function test_register_job_returns_a_job(): void
    {
        $this->assertInstanceOf(Job::class, Ionhour::registerJob('tok_123'));
    }

    public function test_empty_token_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ionhour::registerJob('');
    }

    public function test_whitespace_token_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ionhour::registerJob('has space');
    }

    public function test_tab_and_newline_tokens_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Ionhour::registerJob("tok\t123");
    }

    public function test_facade_resolves_the_manager_singleton(): void
    {
        $this->assertInstanceOf(IonhourManager::class, Ionhour::getFacadeRoot());
        $this->assertSame(
            $this->app->make(IonhourManager::class),
            $this->app->make('ionhour'),
        );
    }

    public function test_config_is_merged_with_package_defaults(): void
    {
        $this->assertSame(self::BASE, config('ionhour.base_url'));
        $this->assertSame(10, config('ionhour.timeout'));
        $this->assertSame(3, config('ionhour.retries'));
        $this->assertNull(config('ionhour.api_key'));
    }

    public function test_config_is_publishable_under_its_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(IonhourServiceProvider::class, 'ionhour-config');

        $this->assertContains($this->app->configPath('ionhour.php'), array_values($paths));
    }
}
