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
 * performs no network call. Token pings authenticate by job token alone and
 * never send an Authorization header; only slug-mode jobs
 * ({@see jobBySlug()}) send the API key, to the API host.
 */
final class IonhourManager
{
    /** Slugs the server accepts on /signals/s/{slug}. */
    private const SLUG_PATTERN = '/^[a-z0-9_-]{1,64}$/D';

    /** Trimmed slug-mode key, or null when absent/blank. Never dumped. */
    private readonly ?string $apiKey;

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $retries,
        private readonly string $apiBaseUrl = 'https://api.ionhour.com/api',
        #[\SensitiveParameter] ?string $apiKey = null,
    ) {
        $trimmed = $apiKey === null ? '' : trim($apiKey);
        $this->apiKey = $trimmed === '' ? null : $trimmed;
    }

    /**
     * Register a Job by its slug in the workspace of the configured API key
     * (slug mode). Pings go to `{api_base_url}/signals/s/{slug}` with
     * `Authorization: Bearer <api_key>`; `['create' => true]` adds `?create=1`
     * so the first ping auto-creates the job (server flag
     * `job_auto_provisioning`, reported via {@see PingResult::$created}).
     *
     * @param  array{create?: bool}  $options
     *
     * @throws InvalidArgumentException on an invalid slug, a non-bool `create`,
     *                                   or a missing/malformed API key.
     */
    public function jobBySlug(string $slug, array $options = []): Job
    {
        if (array_key_exists('create', $options) && ! is_bool($options['create'])) {
            throw new InvalidArgumentException(
                'Ionhour: the "create" option must be a bool (true or false).'
            );
        }

        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidArgumentException(
                'Ionhour: slug must match ^[a-z0-9_-]{1,64}$ (lowercase letters, digits, "_" and "-").'
            );
        }

        if ($this->apiKey === null) {
            throw new InvalidArgumentException(
                'Ionhour: slug mode needs an API key. Set IONHOUR_API_KEY or the ionhour.api_key config value.'
            );
        }

        if (preg_match('/[\s\x00-\x1f\x7f]/', $this->apiKey) === 1) {
            throw new InvalidArgumentException(
                'Ionhour: the API key must not contain whitespace or control characters.'
            );
        }

        return Job::forSlug(
            slug: $slug,
            apiKey: $this->apiKey,
            create: $options['create'] ?? false,
            apiBaseUrl: $this->apiBaseUrl,
            timeout: $this->timeout,
            retries: $this->retries,
        );
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
            'apiBaseUrl' => $this->apiBaseUrl,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
        ];
    }
}
