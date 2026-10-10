<?php

declare(strict_types=1);

namespace Ionhour\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Ionhour\Laravel\IonhourManager;

/**
 * @method static \Ionhour\Laravel\Job registerJob(string $jobToken)
 * @method static \Ionhour\Laravel\PingResult success(string $jobToken, array $options = [])
 * @method static \Ionhour\Laravel\PingResult ping(string $jobToken, array $options = [])
 * @method static \Ionhour\Laravel\PingResult fail(string $jobToken, array $options = [])
 * @method static \Ionhour\Laravel\PingResult start(string $jobToken, array $options = [])
 * @method static mixed run(string $jobToken, \Closure $fn, array $options = [])
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
