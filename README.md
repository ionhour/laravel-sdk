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

## Quickstart

An Ionhour heartbeat is just an HTTP request. The bare success ping is one line
of `curl`:

```bash
curl -m 10 --retry 2 https://signal.ionhour.com/api/signals/ping/your-job-token
```

This package is that same ping with the retries, timeouts, and failure
signaling handled for you — plus scheduler integration and an error philosophy
where a monitoring outage never takes down your app.

The shortest path needs no client construction: credentials and base URL come
from `IONHOUR_*` env, and the token-first facade methods register the job for
you.

```php
use Ionhour\Laravel\Facades\Ionhour;

// One line — the same success ping as the curl above.
Ionhour::success('your-job-token');

// Wrap work: times the closure, pings success with the duration on completion,
// or pings fail (with the error message) and rethrows if the closure throws.
$report = Ionhour::run('your-job-token', fn () => runNightlyReport());

// Report a failure explicitly.
Ionhour::fail('your-job-token', ['payload' => ['error' => 'database unreachable']]);
```

For protected jobs, pass a workspace API key via the `api_key` option; a blank
key falls back to the configured `IONHOUR_API_KEY`:

```php
Ionhour::success('your-job-token', ['api_key' => 'ionh_xxx']);
```

### Scheduled commands

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

### Reusing a job handle

When you ping the same job repeatedly, register it once and keep the handle. The
token-first methods above are thin wrappers over this:

```php
$job = Ionhour::registerJob('your-job-token');       // optional 2nd arg: per-job API key

$job->start(['rid' => 'run-42']);                    // optional run-started ping
$job->success(['rid' => 'run-42', 'payload' => ['rows' => 1200]]);
$job->run(fn () => runNightlyReport());
$job->fail(['payload' => ['error' => 'boom']]);
```

Registration is **local** — it performs no network call and validates the token
(non-empty, no whitespace) immediately.

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
| `timeout`  | `IONHOUR_TIMEOUT`  | `10`                             | Per-attempt timeout, in **seconds** (the Node SDK's `timeout` is milliseconds — this one is not). |
| `retries`  | `IONHOUR_RETRIES`  | `3`                              | Total attempts. Backoff 1s/2s. Retries only on connection errors, 429, 5xx. |

> The `apiKey` is **only** required for protected jobs. For unprotected jobs the
> token *is* the credential; sending a key anyway is always safe.

### Ping options

| Key           | Type    | Meaning                                          |
|---------------|---------|--------------------------------------------------|
| `rid`         | string  | Run correlation id (`?rid=`).                    |
| `duration_ms` | int     | Execution time in ms (`?duration=`).             |
| `host`        | string  | Reporting hostname (`?host=`). Silently truncated over 253 chars. |
| `env`         | string  | Environment name (`?env=`). Silently truncated over 32 chars. |
| `exit_code`   | int     | Process exit code (`?exit_code=`). Must be an integer 0–255, or a usage error is thrown. |
| `payload`     | array   | JSON body (max 10 KiB). Sends `POST` when present, otherwise `GET`. |
| `api_key`     | string  | Per-job workspace API key (token-first methods only). Blank falls back to the configured key. |

`host`, `env`, and `exit_code` are telemetry the server persists but never
auto-detects — pass them explicitly. The server drops invalid `host`/`env`/`rid`
values rather than failing the ping, so the SDK truncates them silently; only a
bad `exit_code` (the one genuine programmer error) throws.

### Start pings and run correlation

Call `start()` to record that a run has begun. It's **persist-only telemetry**:
it never changes monitor status, opens/resolves incidents, or resets the
schedule — a job that starts and then hangs still goes LATE. Pair it with the
terminal ping via a shared `rid`:

```php
Ionhour::start('your-job-token', ['rid' => 'run-42', 'host' => gethostname()]);
// ... work ...
Ionhour::success('your-job-token', ['rid' => 'run-42', 'duration_ms' => 1200]);
```

`run()` does this for you: it sends a best-effort start ping before invoking the
closure, generates a `rid` when you don't supply one, and reuses it on the start
and terminal pings so the pair correlates. A start-ping failure is swallowed and
never affects your result. Opt out with `'start_ping' => false`:

```php
Ionhour::run('your-job-token', fn () => runNightlyReport(), ['start_ping' => false]);
```

> The scheduler macro `->pingIonhour()` has no pre-run hook, so it pings on
> **completion only** (success/fail) — no start ping. Use `run()` or an
> exec-style wrapper when you want start/finish correlation.

## Error philosophy

Pings **never throw** on transport problems — `success()` / `fail()` return a
`PingResult` (`ok`, `status`, `error`) and log a warning. `run()` rethrows the
wrapped closure's own error after pinging fail, but swallows its own ping errors
so a monitoring hiccup can never mask your result. Only *usage* errors (empty
token, payload over 10 KiB, non-serializable payload) throw.

## Testing

The PHP toolchain is not required locally. Run the full suite in Docker:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 \
  sh -c "composer install --no-interaction && vendor/bin/phpunit"
```

## Documentation

## Base URL

The default `base_url` is `https://signal.ionhour.com/api`, the dedicated
heartbeat-ingest host. Set `IONHOUR_BASE_URL=https://api.ionhour.com/api` to
send pings through the main API host instead; it serves every ping route
(`/ping/{token}`, `/fail`, `/start`) and is the right choice when you also use
failure or start pings and your deployment has not yet enabled them on the
signal host.

Full docs at [docs.ionhour.com](https://docs.ionhour.com). Machine-readable index
for AI agents: <https://ionhour.com/llms.txt>.

## License

MIT. See [LICENSE](LICENSE).
