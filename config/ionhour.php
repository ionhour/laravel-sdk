<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace API key
    |--------------------------------------------------------------------------
    |
    | Optional. Only needed for PROTECTED jobs. When set it is sent as
    | `Authorization: Bearer <key>` on every ping. A per-job key passed to
    | registerJob() overrides this global default. Leave null for unprotected
    | jobs — sending a key is always safe (the backend ignores it for those).
    |
    */

    'api_key' => env('IONHOUR_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The Ionhour signals ingest endpoint. Override only for self-hosted or
    | regional installations.
    |
    */

    'base_url' => env('IONHOUR_BASE_URL', 'https://signal.ionhour.com/api'),

    /*
    |--------------------------------------------------------------------------
    | Per-attempt timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Expressed in SECONDS, following Laravel's Http client convention. (The
    | Node SDK's `timeout` is milliseconds — this one is not.)
    |
    */

    'timeout' => (int) env('IONHOUR_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Total attempts
    |--------------------------------------------------------------------------
    |
    | Attempts per ping (not additional retries). Backoff is 1s then 2s.
    | Retries fire only on connection errors, HTTP 429, and 5xx.
    |
    */

    'retries' => (int) env('IONHOUR_RETRIES', 3),

];
