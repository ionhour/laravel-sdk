<?php

declare(strict_types=1);

namespace Ionhour\Laravel;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * A registered Ionhour Job (inbound heartbeat monitor).
 *
 * Instances are cheap, immutable value holders created via
 * {@see IonhourManager::registerJob()}. Pings never throw on transport failure
 * — see the SDK contract's error philosophy. Programmer errors (empty token,
 * oversized/non-serializable payload) DO fail fast and loud.
 */
final class Job
{
    /** Maximum JSON payload the backend accepts (10 KiB). */
    public const MAX_PAYLOAD_BYTES = 10240;

    /** User-Agent sent on every request. */
    public const USER_AGENT = 'ionhour-php/0.1.0';

    public function __construct(
        private readonly string $token,
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $retries,
    ) {
    }

    /**
     * Redact the ping token and key so var_dump()/dd()/print_r() output never
     * leaks credentials into logs. NOTE: __debugInfo() is honored by var_dump()
     * and Symfony's dump()/dd() (what Laravel exposes); print_r() and
     * var_export() ignore it — a PHP-level limitation, not something a class
     * can override.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'token' => '<redacted>',
            'apiKey' => $this->apiKey === null ? null : '<redacted>',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
        ];
    }

    /**
     * Report a successful run. Alias: {@see ping()}.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>}  $options
     */
    public function success(array $options = []): PingResult
    {
        return $this->send(false, $options);
    }

    /**
     * Alias for {@see success()}.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>}  $options
     */
    public function ping(array $options = []): PingResult
    {
        return $this->success($options);
    }

    /**
     * Report a failed run.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>}  $options
     */
    public function fail(array $options = []): PingResult
    {
        return $this->send(true, $options);
    }

    /**
     * Time and wrap a closure: on completion, ping success with the measured
     * duration; on any Throwable, ping fail with the error message merged into
     * the payload, then rethrow so the caller still sees their own failure.
     * Ping-transport errors are swallowed; the closure's error is never masked.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $fn
     * @param  array{rid?: string|int, payload?: array<mixed>}  $options
     * @return TReturn
     */
    public function run(Closure $fn, array $options = []): mixed
    {
        $start = hrtime(true);

        try {
            $result = $fn();
        } catch (Throwable $e) {
            $options['duration_ms'] = $this->elapsedMs($start);
            $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
            $options['payload'] = array_merge($payload, ['error' => $e->getMessage()]);
            $this->fail($options);

            throw $e;
        }

        $options['duration_ms'] = $this->elapsedMs($start);
        $this->success($options);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(bool $isFailure, array $options): PingResult
    {
        // Programmer errors surface BEFORE the try/catch so they stay loud.
        $body = $this->validatePayload($options['payload'] ?? null);
        $url = $this->buildUrl($isFailure, $options);

        try {
            $response = $body === null
                ? $this->request()->get($url)
                : $this->request()->post($url, $body);

            $result = new PingResult(
                ok: $response->successful(),
                status: $response->status(),
                error: $response->successful()
                    ? null
                    : 'Ionhour ping returned HTTP '.$response->status(),
            );
        } catch (Throwable $e) {
            $result = new PingResult(ok: false, status: null, error: $e->getMessage());
        }

        if (! $result->ok) {
            $this->report($isFailure, $result);
        }

        return $result;
    }

    /**
     * Validate a JSON payload, returning it unchanged when valid or null when
     * absent. Throws for non-serializable or oversized payloads.
     *
     * @param  array<mixed>|null  $payload
     * @return array<mixed>|null
     */
    private function validatePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload);

        if ($encoded === false) {
            throw new InvalidArgumentException(
                'Ionhour: payload is not JSON-serializable ('.json_last_error_msg().').'
            );
        }

        $bytes = strlen($encoded);

        if ($bytes > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(
                "Ionhour: payload is {$bytes} bytes, exceeding the ".self::MAX_PAYLOAD_BYTES.'-byte limit.'
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function buildUrl(bool $isFailure, array $options): string
    {
        $url = rtrim($this->baseUrl, '/')
            .'/signals/ping/'.rawurlencode($this->token)
            .($isFailure ? '/fail' : '');

        $query = [];

        if (isset($options['rid']) && $options['rid'] !== '') {
            $query['rid'] = (string) $options['rid'];
        }

        if (isset($options['duration_ms']) && $options['duration_ms'] !== null) {
            $query['duration'] = (string) (int) $options['duration_ms'];
        }

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function request(): PendingRequest
    {
        $request = Http::withUserAgent(self::USER_AGENT)
            ->timeout($this->timeout)
            ->retry(
                max(1, $this->retries),
                fn (int $attempt): int => $attempt <= 1 ? 1000 : 2000,
                fn (Throwable $e): bool => $this->shouldRetry($e),
                false,
            );

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        return $request;
    }

    /**
     * Retry only on connection errors, HTTP 429, and 5xx. Never other 4xx.
     */
    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException && $e->response !== null) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function report(bool $isFailure, PingResult $result): void
    {
        try {
            Log::warning('Ionhour ping did not succeed.', [
                'token' => $this->token,
                'kind' => $isFailure ? 'fail' : 'success',
                'status' => $result->status,
                'error' => $result->error,
            ]);
        } catch (Throwable) {
            // Logging must never break the caller — swallow deliberately.
        }
    }

    private function elapsedMs(int $startHrtime): int
    {
        return (int) round((hrtime(true) - $startHrtime) / 1_000_000);
    }
}
