# Changelog

All notable changes to `ionhour/laravel` are documented here. This project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.1.0 - 2026-07-10

Initial release.

### Added

- `Ionhour` facade + `IonhourManager::registerJob($jobToken, $apiKey?)` local,
  no-network job registration.
- `Job::success()` / `ping()`, `Job::fail()`, and `Job::run(Closure)` timing
  wrapper. Pings never throw on transport failure (they return a `PingResult`);
  `run()` rethrows the wrapped closure's own error after pinging fail.
- `PingResult` value object (`ok`, `status`, `error`).
- GET/POST wire protocol with `rid` and `duration` query params, `/fail`
  suffix, 10 KiB payload guard, and `Authorization: Bearer` support (per-job
  key overrides the configured key).
- Automatic retries (3 attempts, 1s/2s backoff) limited to connection errors,
  HTTP 429, and 5xx.
- Publishable `config/ionhour.php` (`php artisan vendor:publish --tag=ionhour-config`).
- Scheduler macro `->pingIonhour($token, $apiKey?)` for scheduled commands.
