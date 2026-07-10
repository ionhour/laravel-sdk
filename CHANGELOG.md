# Changelog

All notable changes to `ionhour/laravel` are documented here. This project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 - 2026-07-10

Initial release.

### Added

- `Ionhour` facade + `IonhourManager::registerJob($jobToken, $apiKey?)` local,
  no-network job registration.
- Token-first facade conveniences `Ionhour::success($token, $options?)`,
  `::ping()`, `::fail()`, and `::run($token, $fn, $options?)` — register the job
  and ping in a single line, mirroring the NestJS service. A per-job API key may
  be supplied via `$options['api_key']` (trimmed; blank falls back to the
  configured key).
- `Job::success()` / `ping()`, `Job::fail()`, and `Job::run(Closure)` timing
  wrapper. Pings never throw on transport failure (they return a `PingResult`);
  `run()` rethrows the wrapped closure's own error after pinging fail.
- `Job::start()` and token-first `Ionhour::start($token, $options?)` — a
  run-started telemetry ping (`GET|POST {base}/signals/ping/{token}/start`),
  persist-only (never changes monitor status). `run()` now sends a best-effort
  start ping before the closure, generating a `rid` when none is supplied and
  reusing it on the start and terminal pings for correlation; disable via
  `'start_ping' => false`.
- New telemetry ping options `host` (truncated over 253 chars), `env`
  (truncated over 32 chars), and `exit_code` (integer 0–255; a bad value throws)
  mapped to the `host=`/`env=`/`exit_code=` query params. Never auto-detected.
- `PingResult` value object (`ok`, `status`, `error`).
- Automatic failure logger redacts the ping token: it logs at most an 8-character
  prefix (never more than half the token) for attribution, never the raw token
  (which is the credential for unprotected jobs).
- Transport-error text is scrubbed of the token before it reaches
  `PingResult->error` or the failure log: cURL/Guzzle messages embed the request
  URL, so both the raw token and its URL-encoded form are replaced with the same
  truncated prefix.
- GET/POST wire protocol with `rid` and `duration` query params, `/fail`
  suffix, 10 KiB payload guard, and `Authorization: Bearer` support (per-job
  key overrides the configured key).
- Automatic retries (3 attempts, 1s/2s backoff) limited to connection errors,
  HTTP 429, and 5xx.
- Publishable `config/ionhour.php` (`php artisan vendor:publish --tag=ionhour-config`).
- Scheduler macro `->pingIonhour($token, $apiKey?)` for scheduled commands.
