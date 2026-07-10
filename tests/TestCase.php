<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Tests;

use Ionhour\Laravel\Facades\Ionhour;
use Ionhour\Laravel\IonhourServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Base URL used across assertions (the package default; nothing overrides it). */
    protected const BASE = 'https://signal.ionhour.com/api';

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [IonhourServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Ionhour' => Ionhour::class];
    }
}
