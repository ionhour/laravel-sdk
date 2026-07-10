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
 * performs no network call.
 */
final class IonhourManager
{
    private readonly ?string $apiKey;

    public function __construct(
        private readonly string $baseUrl,
        ?string $apiKey,
        private readonly int $timeout,
        private readonly int $retries,
    ) {
        $this->apiKey = self::normalizeKey($apiKey);
    }

    /**
     * Register a Job by its ping token. The optional per-job API key overrides
     * the configured workspace key and is only needed for protected jobs.
     *
     * @throws InvalidArgumentException when the token is empty or contains whitespace.
     */
    public function registerJob(string $jobToken, ?string $apiKey = null): Job
    {
        if ($jobToken === '' || preg_match('/\s/', $jobToken) === 1) {
            throw new InvalidArgumentException(
                'Ionhour: job token must be a non-empty string with no whitespace.'
            );
        }

        return new Job(
            token: $jobToken,
            apiKey: self::normalizeKey($apiKey) ?? $this->apiKey,
            baseUrl: $this->baseUrl,
            timeout: $this->timeout,
            retries: $this->retries,
        );
    }

    /**
     * Token-first convenience: register the job and ping success in one call, so
     * a bare heartbeat is a single line (`Ionhour::success('token')`). Mirrors
     * the NestJS service's token-first surface. Pass an optional per-job API key
     * via $options['api_key'] (trimmed; blank falls back to the configured key).
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int, api_key?: ?string}  $options
     */
    public function success(string $jobToken, array $options = []): PingResult
    {
        [$job, $pingOptions] = $this->resolveJob($jobToken, $options);

        return $job->success($pingOptions);
    }

    /**
     * Alias for {@see success()}.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int, api_key?: ?string}  $options
     */
    public function ping(string $jobToken, array $options = []): PingResult
    {
        return $this->success($jobToken, $options);
    }

    /**
     * Token-first convenience: register the job and ping fail in one call.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int, api_key?: ?string}  $options
     */
    public function fail(string $jobToken, array $options = []): PingResult
    {
        [$job, $pingOptions] = $this->resolveJob($jobToken, $options);

        return $job->fail($pingOptions);
    }

    /**
     * Token-first convenience: register the job and send a run-started telemetry
     * ping in one call. See {@see Job::start()} for the persist-only semantics.
     *
     * @param  array{rid?: string|int, payload?: array<mixed>, host?: string, env?: string, api_key?: ?string}  $options
     */
    public function start(string $jobToken, array $options = []): PingResult
    {
        [$job, $pingOptions] = $this->resolveJob($jobToken, $options);

        return $job->start($pingOptions);
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
     * @param  array{rid?: string|int, payload?: array<mixed>, host?: string, env?: string, exit_code?: int, start_ping?: bool, api_key?: ?string}  $options
     * @return TReturn
     */
    public function run(string $jobToken, Closure $fn, array $options = []): mixed
    {
        [$job, $runOptions] = $this->resolveJob($jobToken, $options);

        return $job->run($fn, $runOptions);
    }

    /**
     * Register the job for a token-first call, pulling the optional per-job API
     * key out of $options so it is never forwarded as a ping option (query or
     * payload). registerJob() normalizes the key (trimmed; blank falls back to
     * the configured key).
     *
     * @param  array<string, mixed>  $options
     * @return array{0: Job, 1: array<string, mixed>}
     */
    private function resolveJob(string $jobToken, array $options): array
    {
        $apiKey = null;

        if (array_key_exists('api_key', $options)) {
            $apiKey = $options['api_key'];

            if ($apiKey !== null && ! is_string($apiKey)) {
                throw new InvalidArgumentException(
                    'Ionhour: the "api_key" option must be a string.'
                );
            }

            unset($options['api_key']);
        }

        return [$this->registerJob($jobToken, $apiKey), $options];
    }

    /**
     * Trim an incoming key and treat an empty/whitespace-only result as absent
     * (null), so a padded ".env" value ("\tionh_x\n") never becomes a malformed
     * "Bearer  ionh_x\n" header that surfaces only as a silent 401.
     */
    private static function normalizeKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $trimmed = trim($key);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Redact the stored workspace key so var_dump()/dd() of the manager never
     * leaks it into logs.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => $this->apiKey === null ? null : '<redacted>',
            'timeout' => $this->timeout,
            'retries' => $this->retries,
        ];
    }
}
