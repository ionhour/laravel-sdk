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

The shortest path needs no client construction: the base URL, timeout and
retries come from `IONHOUR_*` env, and the token-first facade methods register
the job for you.

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

### Slug mode

Address a job by a human-readable slug in your API key's workspace instead of
by token, and optionally create it on the first ping. The curl equivalent:

```bash
curl -H "Authorization: Bearer $IONHOUR_API_KEY" \
  "https://api.ionhour.com/api/signals/s/nightly-report?create=1"
```

```php
// .env: IONHOUR_API_KEY=ionh_... (IONHOUR_API_BASE_URL defaults to https://api.ionhour.com/api)
$job = Ionhour::jobBySlug('nightly-report', ['create' => true]);

$result = $job->success(); // $result->created is true on the ping that created the job
$job->run(fn () => runNightlyReport());
```

The returned `Job` has the same `success` / `fail` / `start` / `run` surface.
Slug pings go to the **API host** with `Authorization: Bearer <key>`; token
pings never send the key. `jobBySlug()` throws `InvalidArgumentException` for a
slug outside `^[a-z0-9_-]{1,64}$`, a non-bool `create`, or when no API key is
configured. A blank `api_key` in a published config file counts as absent; it
does not fall back to `IONHOUR_API_KEY`.

Scheduled commands get a slug twin of `->pingIonhour()`:

```php
$schedule->command('report:nightly')->daily()
    ->pingIonhourBySlug('nightly-report', ['create' => true]);
```

If slug mode is unusable when the command finishes (for example no API key),
the ping is skipped with a log warning instead of interrupting the scheduler.
Auto-creation needs the server flag `job_auto_provisioning`, which is OFF by
default; with it off a create ping returns
`Ionhour ping returned HTTP 403 (FEATURE_DISABLED)` in `$result->error`. Slug
4xx responses are never retried. 429 and 5xx use the usual backoff.

### Scheduled commands

Attach a heartbeat to any scheduled command. Ionhour receives a **success** ping
when the command exits `0`, and a **fail** ping otherwise:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
$schedule->command('backup:run')
    ->daily()
    ->pingIonhour('your-job-token');
```

### Reusing a job handle

When you ping the same job repeatedly, register it once and keep the handle. The
token-first methods above are thin wrappers over this:

```php
$job = Ionhour::registerJob('your-job-token');

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
IONHOUR_BASE_URL=https://signal.ionhour.com/api
```

| Option     | Env                | Default                          | Notes                                                        |
|------------|--------------------|----------------------------------|--------------------------------------------------------------|
| `base_url` | `IONHOUR_BASE_URL` | `https://signal.ionhour.com/api` | Signals ingest endpoint.                                     |
| `timeout`  | `IONHOUR_TIMEOUT`  | `10`                             | Per-attempt timeout, in **seconds** (the Node SDK's `timeout` is milliseconds — this one is not). |
| `retries`  | `IONHOUR_RETRIES`  | `3`                              | Total attempts. Backoff 1s/2s. Retries only on connection errors, 429, 5xx. |
| `api_base_url` | `IONHOUR_API_BASE_URL` | `https://api.ionhour.com/api` | Slug mode only. The API host that serves `/signals/s/{slug}`. |
| `api_key`  | `IONHOUR_API_KEY`  | none                             | Slug mode only, sent as a Bearer header. Trimmed; blank counts as absent. Never shown by `var_dump`/`dd`. |

> The job token *is* the credential. Token pings send no `Authorization`
> header, even when `api_key` is set; only `jobBySlug()` jobs send the key.

### Ping options

| Key           | Type    | Meaning                                          |
|---------------|---------|--------------------------------------------------|
| `rid`         | string  | Run correlation id (`?rid=`).                    |
| `duration_ms` | int     | Execution time in ms (`?duration=`).             |
| `host`        | string  | Reporting hostname (`?host=`). Silently truncated over 253 chars. |
| `env`         | string  | Environment name (`?env=`). Silently truncated over 32 chars. |
| `exit_code`   | int     | Process exit code (`?exit_code=`). Must be an integer 0–255, or a usage error is thrown. |
| `payload`     | array   | JSON body (max 10 KiB). Sends `POST` when present, otherwise `GET`. |

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
