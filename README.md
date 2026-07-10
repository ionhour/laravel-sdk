# Ionhour for Laravel

Register [Ionhour](https://ionhour.com) **Jobs** (inbound heartbeat monitors)
from your Laravel application and ping success/fail with automatic retries,
first-class scheduler integration, and a monitoring-never-takes-down-your-app
error philosophy.

## Installation

```bash
composer require ionhour/laravel
```

The service provider and `Ionhour` facade are auto-discovered. Publish the
config file if you want to tweak defaults:

```bash
php artisan vendor:publish --tag=ionhour-config
```

## Configuration

Add to your `.env`:

```dotenv
IONHOUR_API_KEY=ionh_xxx            # optional — only for protected jobs
IONHOUR_BASE_URL=https://signal.ionhour.com/api
```

| Option     | Env                | Default                          | Notes                                                        |
|------------|--------------------|----------------------------------|--------------------------------------------------------------|
| `api_key`  | `IONHOUR_API_KEY`  | `null`                           | Global default; only needed for protected jobs. A per-job key overrides it. |
| `base_url` | `IONHOUR_BASE_URL` | `https://signal.ionhour.com/api` | Signals ingest endpoint.                                     |
| `timeout`  | `IONHOUR_TIMEOUT`  | `10`                             | Per-attempt timeout, in seconds.                             |
| `retries`  | `IONHOUR_RETRIES`  | `3`                              | Total attempts. Backoff 1s/2s. Retries only on connection errors, 429, 5xx. |

> The `apiKey` is **only** required for protected jobs. For unprotected jobs the
> token *is* the credential; sending a key anyway is always safe.

## Quickstart

```php
use Ionhour\Laravel\Facades\Ionhour;

// Register a job by its ping token (optional per-job API key for protected jobs).
$job = Ionhour::registerJob('your-job-token');

// Wrap work: times the closure, pings success with the duration on completion,
// or pings fail (with the error message) and rethrows if the closure throws.
$report = $job->run(function () {
    return generateNightlyReport();
});

// Or ping manually.
$job->success(['rid' => 'run-42', 'payload' => ['rows' => 1200]]);
$job->fail(['payload' => ['error' => 'database unreachable']]);
```

Pings **never throw** on transport problems — `success()` / `fail()` return a
`PingResult` (`ok`, `status`, `error`) and log a warning. Only *usage* errors
(empty token, payload over 10 KiB, non-serializable payload) throw.

### Ping options

| Key           | Type    | Meaning                                          |
|---------------|---------|--------------------------------------------------|
| `rid`         | string  | Run correlation id (`?rid=`).                    |
| `duration_ms` | int     | Execution time in ms (`?duration=`).             |
| `payload`     | array   | JSON body (max 10 KiB). Sends `POST` when present, otherwise `GET`. |

## Scheduler integration

Attach a heartbeat to any scheduled command. Ionhour receives a **success** ping
when the command exits `0`, and a **fail** ping otherwise:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
$schedule->command('backup:run')
    ->daily()
    ->pingIonhour('your-job-token');

// Protected job — pass a workspace API key as the second argument:
$schedule->command('reports:build')
    ->hourly()
    ->pingIonhour('reports-token', config('services.ionhour.key'));
```

## Documentation

Full docs at [docs.ionhour.com](https://docs.ionhour.com).

## License

MIT. See [LICENSE](LICENSE).
