<?php

declare(strict_types=1);

namespace Ionhour\Laravel;

use Closure;
use InvalidArgumentException;

/**
 * Entry point for the Ionhour Laravel SDK.
 *
 * Bound as a singleton by {@see IonhourServiceProvider} and reached through the
 * {@see \Ionhour\Laravel\Facades\Ionhour} facade. Registration is local and
 * performs no network call. Pings authenticate by job token alone and never
 * send an Authorization header.
 */
final class IonhourManager
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $retries,
    ) {
    }

    /**
     * Register a Job by its ping token.
     *
     * @throws InvalidArgumentException when the token is empty or contains whitespace.
     */
    public function registerJob(string $jobToken): Job
    {
        if ($jobToken === '' || preg_match('/\s/', $jobToken) === 1) {
            throw new InvalidArgumentException(
                'Ionhour: job token must be a non-empty string with no whitespace.'
            );
        }

        return new Job(
            token: $jobToken,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            retries: $this->retries,
        );
    }

    /**
     * Token-first convenience: register the job and ping success in one call, so
     * a bare heartbeat is a single line (`Ionhour::success('token')`). Mirrors
     * the NestJS service's token-first surface.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int}  $options
     */
    public function success(string $jobToken, array $options = []): PingResult
    {
        $job = $this->registerJob($jobToken);

        return $job->success($options);
    }

    /**
     * Alias for {@see success()}.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int}  $options
     */
    public function ping(string $jobToken, array $options = []): PingResult
    {
        return $this->success($jobToken, $options);
    }

    /**
     * Token-first convenience: register the job and ping fail in one call.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int}  $options
     */
    public function fail(string $jobToken, array $options = []): PingResult
    {
        $job = $this->registerJob($jobToken);

        return $job->fail($options);
    }

    /**
     * Token-first convenience: register the job and send a run-started telemetry
     * ping in one call. See {@see Job::start()} for the persist-only semantics.
     *
     * @param  array{rid?: string|int, payload?: array<mixed>, host?: string, env?: string}  $options
     */
    public function start(string $jobToken, array $options = []): PingResult
    {
        $job = $this->registerJob($jobToken);

        return $job->start($options);
    }

    /**
     * Token-first convenience mirroring Cronitor's `$cronitor->job('key', fn)`:
     * register the job and wrap the closure via {@see Job::run()} (times it,
     * pings success with the duration on completion, or pings fail with the
     * error and rethrows). Returns the closure's own result.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $fn
     * @param  array{rid?: string|int, payload?: array<mixed>, host?: string, env?: string, exit_code?: int, start_ping?: bool}  $options
     * @return TReturn
     */
    public function run(string $jobToken, Closure $fn, array $options = []): mixed
    {
        $job = $this->registerJob($jobToken);

        return $job->run($fn, $options);
    }

    /**
     * Curated var_dump()/dd() view of the manager's settings.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
        ];
    }
}
