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

    /** Max length of the `host` telemetry param; longer values are truncated. */
    public const MAX_HOST_CHARS = 253;

    /** Max length of the `env` telemetry param; longer values are truncated. */
    public const MAX_ENV_CHARS = 32;

    /** User-Agent sent on every request. */
    public const USER_AGENT = 'ionhour-php/0.1.0';

    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly int $retries,
    ) {
    }

    /**
     * Redact the ping token so var_dump()/dd()/print_r() output never leaks the
     * credential into logs. NOTE: __debugInfo() is honored by var_dump()
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
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
        ];
    }

    /**
     * Report a successful run. Alias: {@see ping()}.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int}  $options
     */
    public function success(array $options = []): PingResult
    {
        return $this->send('success', $options);
    }

    /**
     * Alias for {@see success()}.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int}  $options
     */
    public function ping(array $options = []): PingResult
    {
        return $this->success($options);
    }

    /**
     * Report a failed run.
     *
     * @param  array{rid?: string|int, duration_ms?: int|float, payload?: array<mixed>, host?: string, env?: string, exit_code?: int}  $options
     */
    public function fail(array $options = []): PingResult
    {
        return $this->send('fail', $options);
    }

    /**
     * Report that a run has started. Persist-only telemetry: it never changes
     * monitor status, opens/resolves incidents, or resets the schedule. Pair it
     * with the terminal ping via a shared `rid` for run correlation (which
     * {@see run()} does automatically).
     *
     * @param  array{rid?: string|int, payload?: array<mixed>, host?: string, env?: string}  $options
     */
    public function start(array $options = []): PingResult
    {
        return $this->send('start', $options);
    }

    /**
     * Time and wrap a closure. Before invoking it, send a best-effort start ping
     * (swallowed on failure); on completion, ping success with the measured
     * duration; on any Throwable, ping fail with the error message merged into
     * the payload, then rethrow so the caller still sees their own failure.
     * Ping-transport errors are swallowed; the closure's error is never masked.
     *
     * When the caller supplies no `rid`, one is generated and reused on the
     * start and terminal pings so the pair correlates. The start ping can be
     * disabled with `'start_ping' => false`.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $fn
     * @param  array{rid?: string|int, payload?: array<mixed>, host?: string, env?: string, exit_code?: int, start_ping?: bool}  $options
     * @return TReturn
     */
    public function run(Closure $fn, array $options = []): mixed
    {
        $startPing = $options['start_ping'] ?? true;
        unset($options['start_ping']);

        // Correlate the start and terminal pings; generate a rid if none given.
        if (! isset($options['rid']) || $options['rid'] === '') {
            $options['rid'] = bin2hex(random_bytes(8));
        }

        if ($startPing !== false) {
            // Best-effort; carries rid/host/env only. send() swallows transport
            // errors, and no usage-error inputs reach it (payload/exit_code are
            // stripped), so it can never disrupt the run.
            $this->start($this->startOptions($options));
        }

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
     * The subset of run() options carried by the start ping: correlation and
     * environment context only — never the payload, duration, or exit code.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function startOptions(array $options): array
    {
        return array_intersect_key($options, array_flip(['rid', 'host', 'env']));
    }

    /**
     * @param  'success'|'fail'|'start'  $kind
     * @param  array<string, mixed>  $options
     */
    private function send(string $kind, array $options): PingResult
    {
        // Programmer errors surface BEFORE the try/catch so they stay loud.
        $body = $this->validatePayload($options['payload'] ?? null);
        $url = $this->buildUrl($this->suffixFor($kind), $options);

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
            $result = new PingResult(ok: false, status: null, error: $this->scrubToken($e->getMessage()));
        }

        if (! $result->ok) {
            $this->report($kind, $result);
        }

        return $result;
    }

    /**
     * The path suffix for each ping kind.
     *
     * @param  'success'|'fail'|'start'  $kind
     */
    private function suffixFor(string $kind): string
    {
        return match ($kind) {
            'fail' => '/fail',
            'start' => '/start',
            default => '',
        };
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

    private function buildUrl(string $suffix, array $options): string
    {
        $url = rtrim($this->baseUrl, '/')
            .'/signals/ping/'.rawurlencode($this->token)
            .$suffix;

        $query = $this->buildQuery($options);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * Assemble the telemetry query params. `host`/`env` are silently truncated
     * to their limits; `exit_code` is validated loudly (a bad exit code is a
     * programmer error). Called before the request is sent so the validation
     * error surfaces outside send()'s transport try/catch.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function buildQuery(array $options): array
    {
        $query = [];

        if (isset($options['rid']) && $options['rid'] !== '') {
            $query['rid'] = (string) $options['rid'];
        }

        if (isset($options['duration_ms']) && $options['duration_ms'] !== null) {
            $query['duration'] = (string) (int) $options['duration_ms'];
        }

        if (isset($options['host']) && $options['host'] !== '') {
            $query['host'] = mb_substr((string) $options['host'], 0, self::MAX_HOST_CHARS);
        }

        if (isset($options['env']) && $options['env'] !== '') {
            $query['env'] = mb_substr((string) $options['env'], 0, self::MAX_ENV_CHARS);
        }

        if (array_key_exists('exit_code', $options) && $options['exit_code'] !== null) {
            $query['exit_code'] = (string) $this->validateExitCode($options['exit_code']);
        }

        return $query;
    }

    /**
     * An exit code must be an integer in the 0–255 range. Anything else is a
     * usage error and fails loudly, matching the payload/token guards.
     */
    private function validateExitCode(mixed $exitCode): int
    {
        if (! is_int($exitCode) || $exitCode < 0 || $exitCode > 255) {
            throw new InvalidArgumentException(
                'Ionhour: the "exit_code" option must be an integer between 0 and 255.'
            );
        }

        return $exitCode;
    }

    private function request(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->timeout($this->timeout)
            ->retry(
                max(1, $this->retries),
                fn (int $attempt): int => $attempt <= 1 ? 1000 : 2000,
                fn (Throwable $e): bool => $this->shouldRetry($e),
                false,
            );
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

    /**
     * @param  'success'|'fail'|'start'  $kind
     */
    private function report(string $kind, PingResult $result): void
    {
        try {
            Log::warning('Ionhour ping did not succeed.', [
                'token' => $this->tokenPrefix(),
                'kind' => $kind,
                'status' => $result->status,
                'error' => $result->error,
            ]);
        } catch (Throwable) {
            // Logging must never break the caller — swallow deliberately.
        }
    }

    /**
     * A short, non-reversible prefix of the ping token for log attribution. The
     * token IS the job's credential, so the automatic failure
     * logger must never write it in full — at most 8 characters, and never more
     * than half the token, per the SDK contract.
     */
    private function tokenPrefix(): string
    {
        $length = min(8, intdiv(strlen($this->token), 2));

        return substr($this->token, 0, $length).'…';
    }

    /**
     * Strip the ping token out of a transport-error string before it reaches
     * PingResult->error or the failure log. cURL/Guzzle messages embed the full
     * request URL (e.g. "cURL error 6: Could not resolve host ... for
     * https://.../signals/ping/<token>"), so the caught exception message would
     * otherwise carry the credential. Both the raw token and its URL-encoded
     * form (the shape that actually appears in the path) are replaced with the
     * same truncated prefix used for the log 'token' field.
     */
    private function scrubToken(string $message): string
    {
        $encoded = rawurlencode($this->token);
        $needles = $encoded === $this->token ? [$this->token] : [$this->token, $encoded];

        return str_replace($needles, $this->tokenPrefix(), $message);
    }

    private function elapsedMs(int $startHrtime): int
    {
        return (int) round((hrtime(true) - $startHrtime) / 1_000_000);
    }
}
