<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ionhour\Laravel\IonhourManager;

/**
 * @method static \Ionhour\Laravel\Job registerJob(string $jobToken, ?string $apiKey = null)
 *
 * @see \Ionhour\Laravel\IonhourManager
 */
final class Ionhour extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IonhourManager::class;
    }
}
